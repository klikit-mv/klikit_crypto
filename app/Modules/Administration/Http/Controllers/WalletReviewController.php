<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Modules\Wallet\WalletRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WalletReviewController extends Controller
{
    public function index(): View
    {
        abort_unless(request()->user()->can('deposits.review') || request()->user()->can('withdrawals.review'), 403);

        return view('admin.wallet.index', [
            'deposits' => Deposit::with('user')->where('status', 'pending')->oldest()->get(),
            'withdrawals' => Withdrawal::with('user')->where('status', 'pending')->oldest()->get(),
            'recent' => Withdrawal::with('user', 'reviewer')->where('status', '!=', 'pending')->latest('reviewed_at')->limit(15)->get(),
        ]);
    }

    public function reviewDeposit(Request $request, Deposit $deposit, WalletRequestService $service): RedirectResponse
    {
        $this->authorize('deposits.review');
        $decision = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]])['decision'];
        $decision === 'approve' ? $service->approveDeposit($deposit, $request->user()) : $service->rejectDeposit($deposit, $request->user());

        return back()->with('status', "Deposit {$decision}d.");
    }

    public function reviewWithdrawal(Request $request, Withdrawal $withdrawal, WalletRequestService $service): RedirectResponse
    {
        $this->authorize('withdrawals.review');
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:255']]);
        abort_if($withdrawal->user_id === $request->user()->id, 403, 'You cannot review your own withdrawal.');

        $data['decision'] === 'approve'
            ? $service->approveWithdrawal($withdrawal, $request->user(), $data['note'] ?? null)
            : $service->rejectWithdrawal($withdrawal, $request->user(), $data['note'] ?? null);

        return back()->with('status', "Withdrawal {$data['decision']}d.");
    }
}
