<?php

namespace Tests\Unit;

use App\Services\Accounting\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbalanced_transactions_are_refused(): void
    {
        $ledger = app(LedgerService::class);

        $this->expectException(InvalidArgumentException::class);
        $ledger->post('adjustment', [
            ['account' => $ledger->systemAccount('clearing', 'USDT'), 'debit' => '10'],
            ['account' => $ledger->systemAccount('fees', 'USDT'), 'credit' => '9.99'],
        ]);
    }

    public function test_balances_are_checked_per_currency(): void
    {
        $ledger = app(LedgerService::class);

        $this->expectException(InvalidArgumentException::class);
        $ledger->post('adjustment', [
            ['account' => $ledger->systemAccount('clearing', 'USDT'), 'debit' => '10'],
            ['account' => $ledger->systemAccount('clearing', 'BTC'), 'credit' => '10'],
        ]);
    }

    public function test_balanced_transaction_is_recorded(): void
    {
        $ledger = app(LedgerService::class);
        $fees = $ledger->systemAccount('fees', 'USDT');

        $ledger->post('adjustment', [
            ['account' => $ledger->systemAccount('clearing', 'USDT'), 'debit' => '10'],
            ['account' => $fees, 'credit' => '10'],
        ]);

        $this->assertSame('10', (string) $ledger->balanceFromEntries($fees)->stripTrailingZeros());
    }
}
