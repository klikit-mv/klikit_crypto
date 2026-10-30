<?php

namespace App\Modules\Wallet\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Modules\Wallet\WalletRequestService;
use App\Services\Accounting\WalletService;
use App\Services\Trading\PortfolioService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    private const AMOUNT = ['required', 'regex:/^\d{1,18}(\.\d{1,18})?$/', 'not_regex:/^0+(\.0+)?$/'];

    public function balances(Request $request, PortfolioService $portfolio): JsonResponse
    {
        $mode = $request->query('mode') === 'live' ? 'live' : 'paper';
        $summary = $portfolio->summary($request->user(), $mode);

        return response()->json(['data' => $summary['wallets'], 'total_usd' => $summary['total_usd'], 'available_usd' => $summary['available_usd']]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $walletIds = Wallet::where('user_id', $request->user()->id)->pluck('id');
        $rows = WalletTransaction::whereIn('wallet_id', $walletIds)
            ->with('wallet:id,currency,wallet_type', 'transaction:id,reference,transaction_type,description')
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows->through(fn (WalletTransaction $t) => [
            'id' => $t->id,
            'reference' => $t->transaction->reference,
            'type' => $t->transaction->transaction_type,
            'description' => $t->transaction->description,
            'currency' => $t->wallet->currency,
            'wallet_type' => $t->wallet->wallet_type,
            'amount' => Decimal::display($t->amount, 18),
            'balance_after' => Decimal::display($t->balance_after, 18),
            'created_at' => $t->created_at,
        ]));
    }

    public function resetPaper(Request $request, WalletService $wallets): JsonResponse
    {
        $wallets->fundPaperAccount($request->user());

        return response()->json(['message' => 'Paper balances topped up.']);
    }

    public function deposits(Request $request): JsonResponse
    {
        return response()->json(Deposit::where('user_id', $request->user()->id)->latest()->paginate(25));
    }

    public function requestDeposit(Request $request, WalletRequestService $service): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'alpha_num', 'max:10'],
            'amount' => self::AMOUNT,
            'method' => ['nullable', Rule::in(['manual', 'bank_transfer', 'crypto'])],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $service->requestDeposit($request->user(), $data)], 201);
    }

    public function withdrawals(Request $request): JsonResponse
    {
        return response()->json(Withdrawal::where('user_id', $request->user()->id)->latest()->paginate(25));
    }

    public function requestWithdrawal(Request $request, WalletRequestService $service): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'alpha_num', 'max:10'],
            'amount' => self::AMOUNT,
            'destination' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $service->requestWithdrawal($request->user(), $data)], 201);
    }
}
