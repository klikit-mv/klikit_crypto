<?php

namespace App\Modules\Trading\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Broker;
use App\Models\BrokerAccount;
use App\Models\Exchange;
use App\Models\ExchangeAccount;
use App\Services\Access\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Users connect their own exchange / broker accounts. Secrets are encrypted at rest and are
 * never returned by the API; only a masked key is shown.
 */
class VenueAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'exchange_accounts' => $user->exchangeAccounts()->with('exchange:id,code,name')->get()->map(fn (ExchangeAccount $a) => $this->present($a, $a->exchange)),
            'broker_accounts' => $user->brokerAccounts()->with('broker:id,code,name')->get()->map(fn (BrokerAccount $a) => $this->present($a, $a->broker)),
        ]);
    }

    public function storeExchange(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'exchange_id' => ['required', Rule::exists('exchanges', 'id')->where('status', 'enabled')],
            'label' => ['required', 'string', 'max:100'],
            'api_key' => ['required', 'string', 'max:255'],
            'api_secret' => ['required', 'string', 'max:255'],
            'passphrase' => ['nullable', 'string', 'max:255'],
            'permissions_scope' => ['nullable', Rule::in(['read_only', 'trade'])],
        ]);

        $account = $request->user()->exchangeAccounts()->create([
            'exchange_id' => $data['exchange_id'],
            'label' => $data['label'],
            'encrypted_credentials' => array_filter(['api_key' => $data['api_key'], 'api_secret' => $data['api_secret'], 'passphrase' => $data['passphrase'] ?? null]),
            'permissions_scope' => $data['permissions_scope'] ?? 'read_only',
            'status' => 'pending',
        ]);
        $audit->log('exchange_account.connected', $request->user(), $account);

        return response()->json(['data' => $this->present($account, Exchange::find($data['exchange_id']))], 201);
    }

    public function storeBroker(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'broker_id' => ['required', Rule::exists('brokers', 'id')->where('status', 'enabled')],
            'label' => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'max:64'],
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'server' => ['nullable', 'string', 'max:255'],
            'permissions_scope' => ['nullable', Rule::in(['read_only', 'trade'])],
        ]);

        $account = $request->user()->brokerAccounts()->create([
            'broker_id' => $data['broker_id'],
            'label' => $data['label'],
            'account_number' => $data['account_number'],
            'encrypted_credentials' => array_filter(['login' => $data['login'], 'password' => $data['password'], 'server' => $data['server'] ?? null]),
            'permissions_scope' => $data['permissions_scope'] ?? 'read_only',
            'status' => 'pending',
        ]);
        $audit->log('broker_account.connected', $request->user(), $account);

        return response()->json(['data' => $this->present($account, Broker::find($data['broker_id']))], 201);
    }

    public function destroyExchange(Request $request, ExchangeAccount $account, AuditLogger $audit): JsonResponse
    {
        abort_unless($account->user_id === $request->user()->id, 404);
        $account->update(['status' => 'revoked', 'encrypted_credentials' => []]);
        $audit->log('exchange_account.revoked', $request->user(), $account);

        return response()->json(['message' => 'Exchange account disconnected.']);
    }

    public function destroyBroker(Request $request, BrokerAccount $account, AuditLogger $audit): JsonResponse
    {
        abort_unless($account->user_id === $request->user()->id, 404);
        $account->update(['status' => 'revoked', 'encrypted_credentials' => []]);
        $audit->log('broker_account.revoked', $request->user(), $account);

        return response()->json(['message' => 'Broker account disconnected.']);
    }

    private function present(ExchangeAccount|BrokerAccount $account, $venue): array
    {
        return [
            'id' => $account->id,
            'venue' => $venue?->only(['id', 'code', 'name']),
            'label' => $account->label,
            'key' => $account->maskedKey(),
            'permissions_scope' => $account->permissions_scope,
            'status' => $account->status,
            'last_verified_at' => $account->last_verified_at,
        ];
    }
}
