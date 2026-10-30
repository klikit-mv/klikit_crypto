<?php

namespace App\Services\Accounting;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Immutable double-entry ledger: the financial source of truth.
 *
 * Wallet accounts are liabilities of the platform towards the user, so a credit increases
 * a wallet balance and a debit decreases it. Every posted transaction must balance per
 * currency. Cached wallet balances are updated in the same database transaction.
 */
class LedgerService
{
    /**
     * @param  list<array{account: LedgerAccount, debit?: BigDecimal|string, credit?: BigDecimal|string}>  $lines
     */
    public function post(string $type, array $lines, ?Model $source = null, ?string $description = null, ?int $createdBy = null): LedgerTransaction
    {
        $this->assertBalanced($lines);

        return DB::transaction(function () use ($type, $lines, $source, $description, $createdBy) {
            $transaction = LedgerTransaction::create([
                'reference' => (string) Str::uuid(),
                'transaction_type' => $type,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'description' => $description,
                'created_by' => $createdBy,
                'created_at' => now(),
            ]);

            foreach ($lines as $line) {
                $debit = Decimal::of($line['debit'] ?? '0');
                $credit = Decimal::of($line['credit'] ?? '0');
                if ($debit->isZero() && $credit->isZero()) {
                    continue;
                }

                LedgerEntry::create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $line['account']->id,
                    'currency' => $line['account']->currency,
                    'debit' => Decimal::str($debit),
                    'credit' => Decimal::str($credit),
                    'created_at' => now(),
                ]);

                $this->applyToWallet($line['account'], $transaction, $credit->minus($debit));
            }

            return $transaction;
        });
    }

    /** System account (clearing, fees, P&L, external funds), created on first use. */
    public function systemAccount(string $purpose, string $currency, string $mode = 'live'): LedgerAccount
    {
        [$type, $name] = match ($purpose) {
            'clearing' => ['asset', 'Trade clearing'],
            'external' => ['asset', 'External funds (deposits/withdrawals)'],
            'paper_funding' => ['equity', 'Paper trading funding'],
            'fees' => ['revenue', 'Trading fees'],
            'pnl' => ['equity', 'Counterparty P&L'],
            default => throw new InvalidArgumentException("Unknown system account purpose [$purpose]."),
        };

        $code = strtoupper("SYS:$mode:$purpose:$currency");

        return LedgerAccount::firstOrCreate(
            ['account_code' => $code],
            ['account_type' => $type, 'currency' => strtoupper($currency), 'name' => "$name ($mode, $currency)"],
        );
    }

    /** Sum of credits minus debits for an account, computed from the immutable entries. */
    public function balanceFromEntries(LedgerAccount $account): BigDecimal
    {
        $row = LedgerEntry::where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(credit), 0) as credits, COALESCE(SUM(debit), 0) as debits')
            ->first();

        return Decimal::of((string) $row->credits)->minus(Decimal::of((string) $row->debits));
    }

    private function applyToWallet(LedgerAccount $account, LedgerTransaction $transaction, BigDecimal $delta): void
    {
        $wallet = Wallet::where('ledger_account_id', $account->id)->lockForUpdate()->first();
        if ($wallet === null) {
            return;
        }

        $newBalance = Decimal::of($wallet->balance)->plus($delta);
        if ($newBalance->isNegative()) {
            throw new LogicException("Ledger posting would make wallet {$wallet->id} negative.");
        }

        $wallet->forceFill(['balance' => Decimal::str($newBalance)])->save();

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'transaction_id' => $transaction->id,
            'amount' => Decimal::str($delta),
            'balance_after' => Decimal::str($newBalance),
            'created_at' => now(),
        ]);
    }

    private function assertBalanced(array $lines): void
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A ledger transaction needs at least one line.');
        }

        $totals = [];
        foreach ($lines as $line) {
            $debit = Decimal::of($line['debit'] ?? '0');
            $credit = Decimal::of($line['credit'] ?? '0');
            if ($debit->isNegative() || $credit->isNegative()) {
                throw new InvalidArgumentException('Ledger amounts must not be negative.');
            }
            $currency = $line['account']->currency;
            $totals[$currency] = ($totals[$currency] ?? BigDecimal::zero())->plus($debit)->minus($credit);
        }

        foreach ($totals as $currency => $net) {
            if (! $net->isZero()) {
                throw new InvalidArgumentException("Ledger transaction is unbalanced for $currency by $net.");
            }
        }
    }
}
