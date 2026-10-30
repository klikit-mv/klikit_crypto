<?php

namespace App\Console\Commands;

use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Accounting\LedgerService;
use App\Support\Decimal;
use Illuminate\Console\Command;

class VerifyLedgerCommand extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Check that the ledger balances and cached wallet balances match ledger entries';

    public function handle(LedgerService $ledger): int
    {
        $problems = 0;

        $unbalanced = LedgerEntry::query()
            ->selectRaw('transaction_id, currency, SUM(debit) as debits, SUM(credit) as credits')
            ->groupBy('transaction_id', 'currency')
            ->get()
            ->filter(fn ($row) => ! Decimal::of((string) $row->debits)->isEqualTo(Decimal::of((string) $row->credits)));
        foreach ($unbalanced as $row) {
            $this->error("Transaction {$row->transaction_id} is unbalanced in {$row->currency}.");
            $problems++;
        }

        Wallet::with('ledgerAccount')->chunkById(500, function ($wallets) use ($ledger, &$problems) {
            foreach ($wallets as $wallet) {
                $expected = $ledger->balanceFromEntries($wallet->ledgerAccount);
                if (! $expected->isEqualTo(Decimal::of($wallet->balance))) {
                    $this->error("Wallet {$wallet->id} ({$wallet->currency}) cached {$wallet->balance} but ledger says $expected.");
                    $problems++;
                }
                if (Decimal::of($wallet->locked_balance)->isNegative()) {
                    $this->error("Wallet {$wallet->id} has a negative locked balance.");
                    $problems++;
                }
            }
        });

        if ($problems === 0) {
            $this->info('Ledger verified: all transactions balance and all wallet balances match.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
