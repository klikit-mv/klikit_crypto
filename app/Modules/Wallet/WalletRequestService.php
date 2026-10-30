<?php

namespace App\Modules\Wallet;

use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Access\AuditLogger;
use App\Services\Accounting\WalletService;
use App\Services\Trading\Exceptions\RiskViolation;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deposits and withdrawals of custodial ("funding") wallets. Both require staff review;
 * users can never change balances themselves.
 */
class WalletRequestService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditLogger $audit,
    ) {}

    public function requestDeposit(User $user, array $data): Deposit
    {
        $wallet = $this->wallets->walletFor($user, $data['currency'], 'funding');
        $deposit = Deposit::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'amount' => Decimal::str($data['amount']),
            'currency' => $wallet->currency,
            'method' => $data['method'] ?? 'manual',
            'reference' => $data['reference'] ?? null,
            'status' => 'pending',
        ]);
        $this->audit->log('deposit.requested', $user, $deposit);

        return $deposit;
    }

    public function requestWithdrawal(User $user, array $data): Withdrawal
    {
        $withdrawal = DB::transaction(function () use ($user, $data) {
            $wallet = $this->wallets->walletFor($user, $data['currency'], 'funding');
            try {
                $this->wallets->lock($wallet, $data['amount']);
            } catch (RiskViolation $e) {
                throw ValidationException::withMessages(['amount' => $e->getMessage()]);
            }

            return Withdrawal::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'amount' => Decimal::str($data['amount']),
                'currency' => $wallet->currency,
                'destination' => $data['destination'],
                'status' => 'pending',
            ]);
        });
        $this->audit->log('withdrawal.requested', $user, $withdrawal);

        return $withdrawal;
    }

    public function approveDeposit(Deposit $deposit, User $reviewer): void
    {
        DB::transaction(function () use ($deposit, $reviewer) {
            $deposit = Deposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($deposit->status);
            $this->wallets->creditDeposit($deposit->wallet, $deposit->amount, $deposit, $reviewer->id);
            $deposit->update(['status' => 'completed', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        });
        $this->audit->log('deposit.approved', $reviewer, $deposit);
    }

    public function rejectDeposit(Deposit $deposit, User $reviewer): void
    {
        DB::transaction(function () use ($deposit, $reviewer) {
            $deposit = Deposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($deposit->status);
            $deposit->update(['status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        });
        $this->audit->log('deposit.rejected', $reviewer, $deposit);
    }

    public function approveWithdrawal(Withdrawal $withdrawal, User $reviewer, ?string $note = null): void
    {
        DB::transaction(function () use ($withdrawal, $reviewer, $note) {
            $withdrawal = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($withdrawal->status);
            $this->wallets->debitWithdrawal($withdrawal->wallet, $withdrawal->amount, $withdrawal, $reviewer->id);
            $withdrawal->update(['status' => 'approved', 'review_note' => $note, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        });
        $this->audit->log('withdrawal.approved', $reviewer, $withdrawal);
    }

    public function rejectWithdrawal(Withdrawal $withdrawal, User $reviewer, ?string $note = null): void
    {
        DB::transaction(function () use ($withdrawal, $reviewer, $note) {
            $withdrawal = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($withdrawal->status);
            $this->wallets->unlock($withdrawal->wallet, $withdrawal->amount);
            $withdrawal->update(['status' => 'rejected', 'review_note' => $note, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        });
        $this->audit->log('withdrawal.rejected', $reviewer, $withdrawal);
    }

    private function assertPending(string $status): void
    {
        if ($status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This request has already been reviewed.']);
        }
    }
}
