<?php

namespace App\Modules\Wallet\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Modules\Wallet\WalletRequestService;
use App\Services\Accounting\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('user.wallet', [
            'wallets' => Wallet::where('user_id', $user->id)->orderBy('wallet_type')->orderBy('currency')->get(),
            'transactions' => WalletTransaction::whereIn('wallet_id', Wallet::where('user_id', $user->id)->pluck('id'))
                ->with('wallet', 'transaction')->latest('id')->limit(30)->get(),
            'deposits' => Deposit::where('user_id', $user->id)->latest()->limit(10)->get(),
            'withdrawals' => Withdrawal::where('user_id', $user->id)->latest()->limit(10)->get(),
        ]);
    }

    public function resetPaper(Request $request, WalletService $wallets): RedirectResponse
    {
        $wallets->fundPaperAccount($request->user());

        return back()->with('status', 'Paper balances topped up.');
    }

    public function deposit(Request $request, WalletRequestService $service): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'alpha_num', 'max:10'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);
        $service->requestDeposit($request->user(), $data);

        return back()->with('status', 'Deposit submitted for review.');
    }

    public function withdraw(Request $request, WalletRequestService $service): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'alpha_num', 'max:10'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'destination' => ['required', 'string', 'max:255'],
        ]);
        $service->requestWithdrawal($request->user(), $data);

        return back()->with('status', 'Withdrawal submitted for review.');
    }
}
