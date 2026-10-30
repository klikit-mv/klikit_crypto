<?php

namespace App\Services\Mining;

use App\Models\MiningPoolAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Pools (or a small adapter in front of a pool's own API) exposing:
 *
 *   GET {api_url}/accounts/{account}/earnings?from=YYYY-MM-DD&to=YYYY-MM-DD
 *   Authorization: Bearer {api key}
 *   -> {"earnings": [{"date": "2026-01-01", "amount": "0.00012", "currency": "BTC"}],
 *       "payouts":  [{"amount": "0.005", "currency": "BTC", "tx_hash": "...", "paid_at": "..."}]}
 */
class GenericJsonPoolClient implements PoolClient
{
    public function fetch(MiningPoolAccount $account, Carbon $from, Carbon $to): array
    {
        $response = Http::baseUrl(rtrim((string) $account->pool->api_url, '/'))
            ->timeout(15)
            ->acceptJson()
            ->when($account->encrypted_api_key, fn ($http, $key) => $http->withToken($key))
            ->get('/accounts/'.rawurlencode($account->account_name).'/earnings', ['from' => $from->toDateString(), 'to' => $to->toDateString()])
            ->throw()
            ->json();

        return ['earnings' => $response['earnings'] ?? [], 'payouts' => $response['payouts'] ?? []];
    }
}
