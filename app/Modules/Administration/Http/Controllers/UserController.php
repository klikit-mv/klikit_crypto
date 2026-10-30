<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\AuditLogger;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Trading\PortfolioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('users.view');
        $users = User::with('roles')
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('email', 'like', "%$term%")->orWhere('name', 'like', "%$term%")))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', ['users' => $users]);
    }

    public function show(User $user, SubscriptionService $subscriptions, PortfolioService $portfolio): View
    {
        $this->authorize('users.view');

        return view('admin.users.show', [
            'user' => $user->load('roles', 'profile', 'riskProfile'),
            'subscription' => $subscriptions->current($user),
            'portfolio' => $portfolio->summary($user),
            'orders' => $user->orders()->with('symbol')->latest()->limit(15)->get(),
            'roles' => Role::orderBy('id')->get(),
            // Connected venues are listed without any credential material.
            'exchangeAccounts' => $user->exchangeAccounts()->with('exchange')->get(),
            'brokerAccounts' => $user->brokerAccounts()->with('broker')->get(),
        ]);
    }

    public function updateStatus(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('users.manage');
        $data = $request->validate(['status' => ['required', Rule::in([User::ACTIVE, User::SUSPENDED, User::CLOSED])]]);
        abort_if($user->is($request->user()), 422, 'You cannot change your own status.');

        $user->forceFill(['status' => $data['status']])->save();
        if ($data['status'] !== User::ACTIVE) {
            $user->tokens()->delete();
        }
        $audit->log('admin.user_status', $request->user(), $user, $data);

        return back()->with('status', "User status set to {$data['status']}.");
    }

    public function updateRoles(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('users.roles');
        $data = $request->validate(['roles' => ['array'], 'roles.*' => ['integer', 'exists:roles,id']]);
        $roleIds = $data['roles'] ?? [];

        // Only a super admin may grant or revoke super admin.
        $superAdminId = Role::where('name', Role::SUPER_ADMIN)->value('id');
        $touchesSuper = in_array($superAdminId, $roleIds) !== $user->hasRole(Role::SUPER_ADMIN);
        abort_if($touchesSuper && ! $request->user()->hasRole(Role::SUPER_ADMIN), 403);

        $user->roles()->sync($roleIds);
        $user->flushAccessCache();
        $audit->log('admin.user_roles', $request->user(), $user, ['roles' => $roleIds]);

        return back()->with('status', 'Roles updated.');
    }
}
