<?php

namespace App\Services\Accounting;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Trading\Exceptions\RiskViolation;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use LogicException;

class WalletService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function walletFor(User|int $user, string $currency, string $type = 'paper'): Wallet
    {
        $userId = $user instanceof User ? $user->id : $user;
        $currency = strtoupper($currency);

        $existing = Wallet::where(['user_id' => $userId, 'currency' => $currency, 'wallet_type' => $type])->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($userId, $currency, $type) {
            $account = LedgerAccount::firstOrCreate(
                ['account_code' => strtoupper("USR:$userId:$type:$currency")],
                ['account_type' => 'liability', 'currency' => $currency, 'user_id' => $userId, 'name' => "User $userId $type $currency"],
            );

            return Wallet::firstOrCreate(
                ['user_id' => $userId, 'currency' => $currency, 'wallet_type' => $type],
                ['ledger_account_id' => $account->id],
            );
        });
    }

    /**
     * Reserve funds for an open order or withdrawal. Must run inside a DB transaction.
     *
     * @throws RiskViolation when the available balance is insufficient
     */
    public function lock(Wallet $wallet, BigDecimal|string $amount): void
    {
        $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
        $amount = Decimal::of($amount);

        if ($wallet->available()->isLessThan($amount)) {
            throw new RiskViolation('insufficient_balance', "Insufficient available {$wallet->currency} balance.");
        }

        $wallet->forceFill(['locked_balance' => Decimal::str(Decimal::of($wallet->locked_balance)->plus($amount))])->save();
    }

    public function unlock(Wallet $wallet, BigDecimal|string $amount): void
    {
        $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
        $locked = Decimal::of($wallet->locked_balance)->minus(Decimal::of($amount));

        if ($locked->isNegative()) {
            throw new LogicException("Unlocking more than is locked in wallet {$wallet->id}.");
        }

        $wallet->forceFill(['locked_balance' => Decimal::str($locked)])->save();
    }

    /** Grant (or top up to) the configured paper-trading starting balances. */
    public function fundPaperAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            foreach (config('trading.paper.starting_balances') as $currency => $target) {
                $wallet = $this->walletFor($user, $currency, 'paper');
                $wallet->refresh();
                $missing = Decimal::of($target)->minus(Decimal::of($wallet->balance));
                if (! $missing->isPositive()) {
                    continue;
                }

                $this->ledger->post('paper_funding', [
                    ['account' => $this->ledger->systemAccount('paper_funding', $currency, 'paper'), 'debit' => $missing],
                    ['account' => $wallet->ledgerAccount, 'credit' => $missing],
                ], $user, 'Paper trading balance top-up');
            }
        });
    }

    /** Credit a confirmed deposit from external funds. */
    public function creditDeposit(Wallet $wallet, BigDecimal|string $amount, $source, ?int $actorId): LedgerTransaction
    {
        return $this->ledger->post('deposit', [
            ['account' => $this->ledger->systemAccount('external', $wallet->currency, $wallet->wallet_type), 'debit' => $amount],
            ['account' => $wallet->ledgerAccount, 'credit' => $amount],
        ], $source, 'Deposit confirmed', $actorId);
    }

    /** Debit an approved withdrawal (funds must already be locked). */
    public function debitWithdrawal(Wallet $wallet, BigDecimal|string $amount, $source, ?int $actorId): LedgerTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $source, $actorId) {
            $this->unlock($wallet, $amount);

            return $this->ledger->post('withdrawal', [
                ['account' => $wallet->ledgerAccount, 'debit' => $amount],
                ['account' => $this->ledger->systemAccount('external', $wallet->currency, $wallet->wallet_type), 'credit' => $amount],
            ], $source, 'Withdrawal approved', $actorId);
        });
    }
}
