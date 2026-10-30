<?php

namespace App\Services\Trading\Adapters;

use App\Models\Order;
use App\Models\Position;
use App\Models\TradingSymbol;
use App\Services\Trading\Contracts\TradingVenueAdapter;
use App\Services\Trading\Data\ExecutionResult;
use App\Services\Trading\Data\Fill;
use App\Services\Trading\Data\Ticker;
use App\Services\Trading\Exceptions\AdapterException;
use App\Support\Decimal;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to the separate execution service over HTTP. The bridge holds no long-term state
 * about users: credentials are decrypted here and sent per request over a private network.
 *
 * Bridge API (JSON): POST /ticker, /orders, /orders/sync, /orders/cancel, /positions/close, /balances, /verify
 */
abstract class HttpBridgeAdapter implements TradingVenueAdapter
{
    /**
     * @param  array<string, mixed>  $credentials  decrypted account credentials
     */
    public function __construct(
        protected readonly string $venueCode,
        protected readonly array $credentials,
        protected readonly array $bridgeConfig,
    ) {}

    public function name(): string
    {
        return $this->venueCode;
    }

    public function ticker(TradingSymbol $symbol): Ticker
    {
        $data = $this->call('/ticker', ['symbol' => $symbol->symbol, 'market_type' => $symbol->market_type]);

        return new Ticker($symbol->symbol, (string) $data['bid'], (string) $data['ask'], (string) $data['last']);
    }

    public function placeOrder(Order $order): ExecutionResult
    {
        return $this->toResult($this->call('/orders', [
            'client_order_id' => $order->uuid,
            'symbol' => $order->symbol->symbol,
            'market_type' => $order->symbol->market_type,
            'side' => $order->side,
            'type' => $order->type,
            'quantity' => (string) $order->quantity,
            'price' => $order->price,
            'stop_price' => $order->stop_price,
            'stop_loss' => $order->stop_loss,
            'take_profit' => $order->take_profit,
            'leverage' => (string) $order->leverage,
        ]));
    }

    public function syncOrder(Order $order): ExecutionResult
    {
        return $this->toResult($this->call('/orders/sync', [
            'client_order_id' => $order->uuid,
            'external_order_id' => $order->external_order_id,
            'symbol' => $order->symbol->symbol,
        ]));
    }

    public function cancelOrder(Order $order): bool
    {
        return (bool) ($this->call('/orders/cancel', [
            'client_order_id' => $order->uuid,
            'external_order_id' => $order->external_order_id,
            'symbol' => $order->symbol->symbol,
        ])['cancelled'] ?? false);
    }

    public function closePosition(Position $position): Fill
    {
        $data = $this->call('/positions/close', [
            'symbol' => $position->symbol->symbol,
            'side' => $position->side,
            'quantity' => (string) $position->quantity,
        ]);

        return $this->toFill($data);
    }

    /**
     * Read-only account check: {"ok": true, "can_trade": bool, "can_withdraw": bool|null, "balances": {...}}.
     */
    public function verify(): array
    {
        return $this->call('/verify');
    }

    public function balances(): array
    {
        return array_map('strval', $this->call('/balances')['balances'] ?? []);
    }

    protected function call(string $path, array $payload = []): array
    {
        try {
            $response = $this->client()->post($path, $payload + [
                'venue' => $this->venueCode,
                'credentials' => $this->credentials,
            ]);
        } catch (Throwable $e) {
            throw new AdapterException("Bridge request to {$this->venueCode} failed: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            throw new AdapterException("Bridge returned HTTP {$response->status()} for {$this->venueCode}{$path}.");
        }

        return $response->json() ?? [];
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->bridgeConfig['url'])
            ->timeout($this->bridgeConfig['timeout'] ?? 10)
            ->acceptJson()
            ->when($this->bridgeConfig['token'] ?? null, fn ($http, $token) => $http->withToken($token));
    }

    private function toResult(array $data): ExecutionResult
    {
        return new ExecutionResult(
            $data['status'] ?? 'open',
            array_map(fn (array $fill) => $this->toFill($fill), $data['fills'] ?? []),
            $data['external_order_id'] ?? null,
            $data['message'] ?? null,
        );
    }

    private function toFill(array $data): Fill
    {
        return new Fill(
            Decimal::str((string) $data['price']),
            Decimal::str((string) $data['quantity']),
            Decimal::str((string) ($data['fee'] ?? '0')),
            (string) ($data['fee_currency'] ?? ''),
            $data['trade_id'] ?? null,
        );
    }
}
