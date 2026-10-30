<?php

namespace App\Modules\Mining\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MiningDevice;
use App\Models\MiningEarning;
use App\Models\MiningPayout;
use App\Models\MiningPool;
use App\Models\MiningPoolAccount;
use App\Models\MiningProfitability;
use App\Models\MiningStat;
use App\Services\Mining\MiningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Requires the mining.management entitlement (route middleware). */
class MiningController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $user = $request->user();
        $devices = MiningDevice::where('user_id', $user->id)->with('pool', 'workers')->get();
        $since = now()->subDays(29)->toDateString();
        $profit = MiningProfitability::whereIn('device_id', $devices->pluck('id'))->where('date', '>=', $since)->orderBy('date')->get();
        $daily = $profit->groupBy('date')->map(fn ($rows, $date) => [
            'date' => $date,
            'revenue' => round($rows->sum(fn ($r) => (float) $r->revenue), 2),
            'expenses' => round($rows->sum(fn ($r) => (float) $r->expenses), 2),
            'net_profit' => round($rows->sum(fn ($r) => (float) $r->net_profit), 2),
        ])->values();
        $latest = $this->latestStats($devices);

        if ($request->expectsJson()) {
            return response()->json([
                'devices' => $devices->map(fn ($d) => $d->only(['id', 'name', 'device_type', 'model', 'status', 'power_watts', 'electricity_rate', 'last_seen_at']) + [
                    'pool' => $d->pool?->name, 'hashrate' => $latest[$d->id]['hashrate'] ?? 0, 'temperature' => $latest[$d->id]['temperature'] ?? null,
                ]),
                'profitability_30d' => $daily,
            ]);
        }

        return view('mining.index', [
            'devices' => $devices,
            'latest' => $latest,
            'daily' => $daily,
            'totals' => ['revenue' => $daily->sum('revenue'), 'expenses' => $daily->sum('expenses'), 'net' => $daily->sum('net_profit')],
            'pools' => MiningPool::where('is_active', true)->orderBy('name')->get(),
            'poolAccounts' => MiningPoolAccount::where('user_id', $user->id)->with('pool')->get(),
            'earnings' => MiningEarning::where('user_id', $user->id)->latest('date')->limit(15)->get(),
            'payouts' => MiningPayout::where('user_id', $user->id)->latest('paid_at')->limit(10)->get(),
        ]);
    }

    public function storeDevice(Request $request, MiningService $mining): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'device_type' => ['required', Rule::in(['asic', 'gpu', 'cpu'])],
            'model' => ['nullable', 'string', 'max:100'],
            'pool_id' => ['nullable', Rule::exists('mining_pools', 'id')->where('is_active', true)],
            'power_watts' => ['required', 'integer', 'min:0', 'max:100000'],
            'electricity_rate' => ['required', 'numeric', 'min:0', 'max:10'],
        ]);
        [$device, $token] = $mining->registerDevice($request->user(), $data);

        return $request->expectsJson()
            ? response()->json(['data' => $device, 'agent_token' => $token], 201)
            : back()->with('status', 'Device added.')->with('agent_token', $token);
    }

    public function showDevice(Request $request, MiningDevice $device): View|JsonResponse
    {
        $this->owns($request, $device);
        $device->load('workers', 'pool');
        $stats = MiningStat::whereIn('worker_id', $device->workers->pluck('id'))->where('recorded_at', '>=', now()->subDay())->orderBy('recorded_at')->get();
        $series = $stats->groupBy(fn ($s) => $s->recorded_at->copy()->startOfMinute()->getTimestamp() - $s->recorded_at->minute % 5 * 60)
            ->map(fn ($rows, $t) => ['time' => (int) $t, 'value' => round($rows->sum(fn ($r) => (float) $r->hashrate), 2)])->values();

        if ($request->expectsJson()) {
            return response()->json(['data' => $device, 'hashrate_24h' => $series]);
        }

        return view('mining.device', [
            'device' => $device,
            'series' => $series,
            'profit' => MiningProfitability::where('device_id', $device->id)->latest('date')->limit(30)->get(),
        ]);
    }

    public function rotateToken(Request $request, MiningDevice $device, MiningService $mining): JsonResponse|RedirectResponse
    {
        $this->owns($request, $device);
        $token = $mining->rotateToken($device);

        return $request->expectsJson() ? response()->json(['agent_token' => $token]) : back()->with('agent_token', $token)->with('status', 'New agent token issued; the old one no longer works.');
    }

    public function destroyDevice(Request $request, MiningDevice $device): JsonResponse|RedirectResponse
    {
        $this->owns($request, $device);
        $device->delete();

        return $request->expectsJson() ? response()->json(['message' => 'Device removed.']) : redirect()->route('mining.index')->with('status', 'Device removed.');
    }

    public function storePoolAccount(Request $request, MiningService $mining): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'pool_id' => ['required', Rule::exists('mining_pools', 'id')->where('is_active', true)],
            'account_name' => ['required', 'string', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);
        $account = MiningPoolAccount::updateOrCreate(
            ['user_id' => $request->user()->id, 'pool_id' => $data['pool_id'], 'account_name' => $data['account_name']],
            ['encrypted_api_key' => $data['api_key'] ?? null],
        );

        return $request->expectsJson() ? response()->json(['data' => $account], 201) : back()->with('status', 'Pool account linked; earnings sync hourly.');
    }

    public function storeEarning(Request $request, MiningService $mining): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'pool_id' => ['nullable', 'exists:mining_pools,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'alpha_num', 'max:10'],
            'date' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $earning = $mining->recordEarning($request->user(), $data['pool_id'] ?? null, (string) $data['amount'], $data['currency'], Carbon::parse($data['date'])->toDateString());

        return $request->expectsJson() ? response()->json(['data' => $earning], 201) : back()->with('status', 'Earning recorded.');
    }

    public function storeExpense(Request $request, MiningDevice $device, MiningService $mining): JsonResponse|RedirectResponse
    {
        $this->owns($request, $device);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'date' => ['required', 'date', 'before_or_equal:today']]);
        $mining->addOtherCost($device, Carbon::parse($data['date'])->toDateString(), (string) $data['amount']);
        $mining->computeDay($device, Carbon::parse($data['date']));

        return $request->expectsJson() ? response()->json(['message' => 'Cost recorded.'], 201) : back()->with('status', 'Cost recorded.');
    }

    public function statistics(Request $request): JsonResponse
    {
        $devices = MiningDevice::where('user_id', $request->user()->id)->with('workers')->get();

        return response()->json(['data' => $this->latestStats($devices)]);
    }

    private function latestStats($devices): array
    {
        $out = [];
        foreach ($devices as $device) {
            $workerIds = $device->workers->pluck('id');
            $recent = MiningStat::whereIn('worker_id', $workerIds)->where('recorded_at', '>=', now()->subMinutes(MiningService::OFFLINE_AFTER_MINUTES))->get()
                ->sortByDesc('recorded_at')->unique('worker_id');
            $out[$device->id] = [
                'hashrate' => round($recent->sum(fn ($s) => (float) $s->hashrate), 2),
                'temperature' => $recent->max(fn ($s) => $s->temperature !== null ? (float) $s->temperature : null),
                'workers_online' => $device->workers->where('status', 'online')->count(),
                'workers' => $device->workers->count(),
            ];
        }

        return $out;
    }

    private function owns(Request $request, MiningDevice $device): void
    {
        abort_unless($device->user_id === $request->user()->id, 404);
    }
}
