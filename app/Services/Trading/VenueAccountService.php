<?php

namespace App\Services\Trading;

use App\Models\BrokerAccount;
use App\Models\ExchangeAccount;
use App\Services\Access\AuditLogger;
use App\Services\Trading\Adapters\CcxtBridgeExchangeAdapter;
use App\Services\Trading\Adapters\HttpBridgeAdapter;
use App\Services\Trading\Adapters\Mt5BridgeBrokerAdapter;
use Throwable;

/**
 * Verifies connected exchange/broker credentials through the execution bridge (read-only call).
 * Keys that can withdraw funds are refused: the platform only ever needs trade permission.
 */
class VenueAccountService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function verify(ExchangeAccount|BrokerAccount $account): ExchangeAccount|BrokerAccount
    {
        try {
            $result = $this->adapter($account)->verify();
        } catch (Throwable $e) {
            return $this->mark($account, 'invalid', 'Verification failed: '.substr($e->getMessage(), 0, 200));
        }

        if (! ($result['ok'] ?? false)) {
            return $this->mark($account, 'invalid', $result['message'] ?? 'Credentials were rejected by the venue.');
        }
        if (($result['can_withdraw'] ?? false) === true) {
            return $this->mark($account, 'invalid', 'This API key has withdrawal permission. Create a key without withdrawals.');
        }
        if ($account->permissions_scope === 'trade' && ! ($result['can_trade'] ?? false)) {
            return $this->mark($account, 'invalid', 'This API key cannot trade; enable trading or connect it as read-only.');
        }

        $account->forceFill(['last_verified_at' => now()]);

        return $this->mark($account, 'verified', null);
    }

    private function adapter(ExchangeAccount|BrokerAccount $account): HttpBridgeAdapter
    {
        return $account instanceof BrokerAccount
            ? new Mt5BridgeBrokerAdapter($account->broker->code, $account->encrypted_credentials, config('trading.bridges.mt5'))
            : new CcxtBridgeExchangeAdapter($account->exchange->code, $account->encrypted_credentials, config('trading.bridges.ccxt'));
    }

    private function mark(ExchangeAccount|BrokerAccount $account, string $status, ?string $reason): ExchangeAccount|BrokerAccount
    {
        $account->forceFill(['status' => $status, 'status_reason' => $reason])->save();
        $this->audit->log('venue_account.verified', $account->user, $account, ['status' => $status, 'reason' => $reason]);

        return $account;
    }
}
