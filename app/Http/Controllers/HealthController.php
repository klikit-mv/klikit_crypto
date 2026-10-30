<?php

namespace App\Http\Controllers;

use App\Models\MarketPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Readiness probe for load balancers and monitoring (/up is Laravel's liveness probe).
 * Returns 503 when a critical dependency is down; warnings do not fail the check.
 */
class HealthController extends Controller
{
    public const SCHEDULER_HEARTBEAT_KEY = 'health:scheduler_heartbeat';

    public function __invoke(): JsonResponse
    {
        $checks = [];
        $critical = true;

        try {
            DB::select('select 1');
            $checks['database'] = 'ok';
        } catch (Throwable $e) {
            $checks['database'] = 'fail';
            $critical = false;
        }

        try {
            Cache::put('health:probe', 1, 10);
            $checks['cache'] = Cache::get('health:probe') === 1 ? 'ok' : 'fail';
        } catch (Throwable) {
            $checks['cache'] = 'fail';
        }
        $critical = $critical && $checks['cache'] === 'ok';

        $heartbeat = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
        $checks['scheduler'] = $heartbeat && now()->getTimestamp() - $heartbeat < 180 ? 'ok' : 'warn';

        $lastPrice = rescue(fn () => MarketPrice::max('timestamp'), null, false);
        $checks['market_data'] = $lastPrice && now()->diffInMinutes($lastPrice, true) < 5 ? 'ok' : 'warn';

        $checks['failed_jobs'] = rescue(fn () => DB::table('failed_jobs')->count(), null, false);

        return response()->json(['status' => $critical ? 'ok' : 'fail', 'checks' => $checks], $critical ? 200 : 503);
    }
}
