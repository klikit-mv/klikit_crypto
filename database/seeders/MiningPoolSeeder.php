<?php

namespace Database\Seeders;

use App\Models\MiningPool;
use Illuminate\Database\Seeder;

/** Example pools; earnings are entered manually until an API adapter URL is configured. */
class MiningPoolSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Braiins Pool', 'stratum+tcp://stratum.braiins.com:3333', 'SHA-256', 'BTC', 2.0],
            ['F2Pool BTC', 'stratum+tcp://btc.f2pool.com:1314', 'SHA-256', 'BTC', 2.5],
            ['2Miners ETC', 'stratum+tcp://etc.2miners.com:1010', 'Etchash', 'ETC', 1.0],
        ] as [$name, $endpoint, $algorithm, $coin, $fee]) {
            MiningPool::firstOrCreate(['name' => $name], ['endpoint' => $endpoint, 'algorithm' => $algorithm, 'coin' => $coin, 'fee_percent' => $fee, 'api_type' => 'manual']);
        }
    }
}
