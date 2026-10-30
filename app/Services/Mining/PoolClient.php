<?php

namespace App\Services\Mining;

use App\Models\MiningPoolAccount;
use Illuminate\Support\Carbon;

interface PoolClient
{
    /**
     * @return array{earnings: list<array{date: string, amount: string, currency: string, external_id?: string}>, payouts: list<array{amount: string, currency: string, tx_hash?: string, paid_at?: string}>}
     */
    public function fetch(MiningPoolAccount $account, Carbon $from, Carbon $to): array;
}
