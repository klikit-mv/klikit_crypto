<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique(); // binance, kraken, paper ...
            $table->string('name');
            $table->string('api_type', 20)->default('ccxt'); // ccxt, native, paper
            $table->string('status', 20)->default('disabled')->index(); // enabled, disabled, maintenance
            $table->boolean('supports_spot')->default(true);
            $table->boolean('supports_futures')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('brokers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('integration_type', 20)->default('mt5_bridge'); // mt5_bridge, rest, paper
            $table->string('status', 20)->default('disabled')->index();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('exchange_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exchange_id')->constrained();
            $table->string('label');
            $table->text('encrypted_credentials');
            $table->string('permissions_scope', 20)->default('read_only'); // read_only, trade
            $table->string('status', 20)->default('pending')->index(); // pending, verified, invalid, revoked
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('broker_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broker_id')->constrained();
            $table->string('label');
            $table->string('account_number', 64)->nullable();
            $table->text('encrypted_credentials');
            $table->string('permissions_scope', 20)->default('read_only');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('trading_symbols', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 40); // BTC/USDT, EUR/USD
            $table->string('market_type', 10)->index(); // SPOT, FUTURES, FOREX, CFD
            $table->string('base_currency', 10);
            $table->string('quote_currency', 10);
            $table->unsignedTinyInteger('price_precision')->default(8);
            $table->unsignedTinyInteger('quantity_precision')->default(8);
            $table->decimal('min_quantity', 36, 18)->default(0);
            $table->decimal('max_leverage', 10, 2)->default(1);
            $table->decimal('contract_size', 36, 18)->default(1); // e.g. 100000 for a standard FX lot
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['symbol', 'market_type']);
        });

        Schema::create('market_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('trading_symbols')->cascadeOnDelete();
            $table->string('source', 40)->default('seed');
            $table->decimal('bid', 36, 18);
            $table->decimal('ask', 36, 18);
            $table->decimal('price', 36, 18);
            $table->decimal('change_24h', 12, 4)->nullable();
            $table->decimal('volume_24h', 36, 18)->nullable();
            $table->timestamp('timestamp')->index();
            $table->index(['symbol_id', 'timestamp']);
        });

        Schema::create('risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('max_amount_per_trade', 20, 8)->default(1000);
            $table->decimal('max_daily_loss', 20, 8)->default(500);
            $table->unsignedInteger('max_open_positions')->default(5);
            $table->decimal('max_leverage', 10, 2)->default(1);
            $table->boolean('require_stop_loss')->default(false);
            $table->decimal('max_total_exposure', 20, 8)->default(10000);
            $table->boolean('trading_enabled')->default(true); // emergency shutdown switch
            $table->string('disabled_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('symbol_id')->constrained('trading_symbols');
            $table->foreignId('exchange_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('mode', 10)->default('paper'); // paper, live
            $table->string('source', 20)->default('manual'); // manual, signal, bot
            $table->string('side', 4); // buy, sell
            $table->string('type', 20); // market, limit, stop_limit
            $table->decimal('quantity', 36, 18);
            $table->decimal('price', 36, 18)->nullable();
            $table->decimal('stop_price', 36, 18)->nullable();
            $table->decimal('stop_loss', 36, 18)->nullable();
            $table->decimal('take_profit', 36, 18)->nullable();
            $table->decimal('leverage', 10, 2)->default(1);
            $table->decimal('filled_quantity', 36, 18)->default(0);
            $table->decimal('average_fill_price', 36, 18)->nullable();
            $table->decimal('reserved_amount', 36, 18)->default(0);
            $table->string('reserved_currency', 10)->nullable();
            $table->string('status', 20)->index(); // pending, open, partially_filled, filled, cancelled, rejected, failed
            $table->string('external_order_id')->nullable()->index();
            $table->string('reject_reason')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('order_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('execution_price', 36, 18);
            $table->decimal('quantity', 36, 18);
            $table->decimal('fee', 36, 18)->default(0);
            $table->string('fee_currency', 10)->nullable();
            $table->string('external_trade_id')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('symbol_id')->constrained('trading_symbols');
            $table->string('mode', 10)->default('paper');
            $table->string('side', 5); // long, short
            $table->decimal('quantity', 36, 18);
            $table->decimal('average_price', 36, 18);
            $table->decimal('leverage', 10, 2)->default(1);
            $table->decimal('margin', 36, 18)->default(0);
            $table->string('margin_currency', 10);
            $table->decimal('stop_loss', 36, 18)->nullable();
            $table->decimal('take_profit', 36, 18)->nullable();
            $table->decimal('realized_pnl', 36, 18)->default(0);
            $table->string('status', 10)->default('open')->index(); // open, closed
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('symbol_id')->constrained('trading_symbols');
            $table->string('mode', 10)->default('paper');
            $table->string('side', 5);
            $table->decimal('quantity', 36, 18);
            $table->decimal('entry_price', 36, 18);
            $table->decimal('exit_price', 36, 18)->nullable();
            $table->decimal('fee', 36, 18)->default(0);
            $table->decimal('pnl', 36, 18)->nullable();
            $table->string('pnl_currency', 10)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('order_executions');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('risk_profiles');
        Schema::dropIfExists('market_prices');
        Schema::dropIfExists('trading_symbols');
        Schema::dropIfExists('broker_accounts');
        Schema::dropIfExists('exchange_accounts');
        Schema::dropIfExists('brokers');
        Schema::dropIfExists('exchanges');
    }
};
