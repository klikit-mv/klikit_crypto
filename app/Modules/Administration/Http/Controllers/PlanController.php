<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\Access\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        $this->authorize('plans.manage');

        return view('admin.plans.index', ['plans' => SubscriptionPlan::with('features')->withCount('subscriptions')->orderBy('sort_order')->get()]);
    }

    public function create(): View
    {
        $this->authorize('plans.manage');

        return view('admin.plans.form', ['plan' => new SubscriptionPlan(['currency' => 'USD', 'billing_period' => 'monthly', 'is_active' => true]), 'featureLabels' => config('plans.features')]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('plans.manage');
        $plan = $this->save(new SubscriptionPlan, $request);
        $audit->log('admin.plan_created', $request->user(), $plan);

        return redirect()->route('admin.plans.index')->with('status', 'Plan created.');
    }

    public function edit(SubscriptionPlan $plan): View
    {
        $this->authorize('plans.manage');

        return view('admin.plans.form', ['plan' => $plan->load('features'), 'featureLabels' => config('plans.features')]);
    }

    public function update(Request $request, SubscriptionPlan $plan, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('plans.manage');
        $this->save($plan, $request);
        $audit->log('admin.plan_updated', $request->user(), $plan);

        return redirect()->route('admin.plans.index')->with('status', 'Plan updated.');
    }

    private function save(SubscriptionPlan $plan, Request $request): SubscriptionPlan
    {
        $data = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:64', Rule::unique('subscription_plans', 'slug')->ignore($plan->id)],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_period' => ['required', Rule::in(['monthly', 'quarterly', 'yearly'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'features' => ['array'],
            'features.*' => ['nullable', 'string', 'max:50'],
        ]);

        return DB::transaction(function () use ($plan, $data, $request) {
            $plan->fill($data + ['is_active' => $request->boolean('is_active'), 'is_featured' => $request->boolean('is_featured')])->save();

            foreach (array_keys(config('plans.features')) as $key) {
                $value = $data['features'][$key] ?? null;
                if ($value === null || $value === '') {
                    $plan->features()->where('feature_key', $key)->delete();
                } else {
                    $plan->features()->updateOrCreate(['feature_key' => $key], ['feature_value' => $value]);
                }
            }

            return $plan;
        });
    }
}
