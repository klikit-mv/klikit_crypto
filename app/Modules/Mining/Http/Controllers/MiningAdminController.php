<?php

namespace App\Modules\Mining\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MiningDevice;
use App\Models\MiningPool;
use App\Services\Access\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MiningAdminController extends Controller
{
    public function index(): View
    {
        $this->authorize('mining.manage');

        return view('admin.mining.index', [
            'pools' => MiningPool::withCount('accounts')->orderBy('name')->get(),
            'devices' => MiningDevice::with('user', 'pool')->latest('last_seen_at')->limit(100)->get(),
        ]);
    }

    public function storePool(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('mining.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'endpoint' => ['required', 'string', 'max:255'],
            'algorithm' => ['required', 'string', 'max:40'],
            'coin' => ['required', 'string', 'max:10'],
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'api_type' => ['required', Rule::in(['generic_json', 'manual'])],
            'api_url' => ['nullable', 'url:https', 'max:255'],
        ]);
        $pool = MiningPool::create($data + ['is_active' => true]);
        $audit->log('admin.mining_pool_added', $request->user(), $pool);

        return back()->with('status', 'Pool added.');
    }

    public function togglePool(Request $request, MiningPool $pool): RedirectResponse
    {
        $this->authorize('mining.manage');
        $pool->update(['is_active' => ! $pool->is_active]);

        return back()->with('status', $pool->name.($pool->is_active ? ' enabled.' : ' disabled.'));
    }
}
