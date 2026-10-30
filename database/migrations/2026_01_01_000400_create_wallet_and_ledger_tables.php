<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 100)->unique();
            $table->string('account_type', 20); // asset, liability, equity, revenue, expense
            $table->string('currency', 10);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 10);
            $table->string('wallet_type', 10)->default('paper'); // paper, spot, funding
            $table->foreignId('ledger_account_id')->unique()->constrained();
            // Cached balances. Only LedgerService / WalletService may change them, always inside
            // the same DB transaction as the ledger entries they are derived from.
            $table->decimal('balance', 36, 18)->default(0);
            $table->decimal('locked_balance', 36, 18)->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'currency', 'wallet_type']);
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('transaction_type', 40)->index(); // deposit, withdrawal, trade, fee, pnl, paper_funding, adjustment
            $table->nullableMorphs('source');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('ledger_transactions');
            $table->foreignId('account_id')->constrained('ledger_accounts');
            $table->string('currency', 10);
            $table->decimal('debit', 36, 18)->default(0);
            $table->decimal('credit', 36, 18)->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['account_id', 'created_at']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('ledger_transactions');
            $table->decimal('amount', 36, 18); // signed: + credit to wallet, - debit
            $table->decimal('balance_after', 36, 18);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained();
            $table->decimal('amount', 36, 18);
            $table->string('currency', 10);
            $table->string('method', 30)->default('manual');
            $table->string('reference')->nullable();
            $table->string('status', 20)->default('pending')->index(); // pending, completed, rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained();
            $table->decimal('amount', 36, 18);
            $table->string('currency', 10);
            $table->string('destination');
            $table->string('status', 20)->default('pending')->index(); // pending, approved, rejected, completed
            $table->string('review_note')->nullable();
            $table->string('transaction_hash')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('ledger_accounts');
    }
};
