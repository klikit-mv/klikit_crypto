<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 5 – chart analysis
        Schema::create('market_candles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('trading_symbols')->cascadeOnDelete();
            $table->string('interval', 4); // 1m, 5m, 15m, 1h, 4h, 1d
            $table->timestamp('open_time');
            $table->decimal('open', 36, 18);
            $table->decimal('high', 36, 18);
            $table->decimal('low', 36, 18);
            $table->decimal('close', 36, 18);
            $table->decimal('volume', 36, 18)->default(0);
            $table->unique(['symbol_id', 'interval', 'open_time']);
        });

        Schema::create('price_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('symbol_id')->constrained('trading_symbols')->cascadeOnDelete();
            $table->string('condition', 20); // price_above, price_below, rsi_above, rsi_below
            $table->decimal('target', 36, 18);
            $table->string('interval', 4)->default('1h'); // for indicator alerts
            $table->string('note')->nullable();
            $table->string('status', 20)->default('active')->index(); // active, triggered, cancelled
            $table->decimal('triggered_value', 36, 18)->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamps();
        });

        // Phase 4 – Telegram signals
        Schema::table('telegram_channels', function (Blueprint $table) {
            $table->string('webhook_secret', 64)->nullable();
            $table->unsignedInteger('signal_ttl_minutes')->default(60);
        });

        Schema::table('telegram_signals', function (Blueprint $table) {
            $table->string('message_id', 64)->nullable();
            $table->string('market_type', 10)->nullable();
            $table->decimal('leverage', 10, 2)->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->unique(['channel_id', 'message_id']);
        });

        Schema::create('signal_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('telegram_channels')->cascadeOnDelete();
            $table->string('execution_mode', 20)->default('manual_approval'); // manual_approval, auto
            $table->decimal('amount_per_signal', 20, 8)->default(100); // USD-equivalent notional
            $table->decimal('max_leverage', 10, 2)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'channel_id']);
        });

        Schema::table('signal_executions', function (Blueprint $table) {
            $table->string('reason')->nullable();
        });

        // Phase 6 – bots
        Schema::table('trading_bots', function (Blueprint $table) {
            $table->json('state')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_error')->nullable();
        });

        Schema::create('bot_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('trading_bots')->cascadeOnDelete();
            $table->string('side', 4);
            $table->string('type', 10)->default('market');
            $table->decimal('quantity', 36, 18);
            $table->decimal('price', 36, 18)->nullable();
            $table->string('reason');
            $table->string('status', 20)->default('pending_approval'); // pending_approval, approved, rejected, executed, failed, expired
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Phase 7 – mining
        Schema::table('mining_pools', function (Blueprint $table) {
            $table->string('api_type', 30)->default('generic_json'); // generic_json, manual
            $table->string('api_url')->nullable();
        });

        Schema::create('mining_pool_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_id')->constrained('mining_pools')->cascadeOnDelete();
            $table->string('account_name');
            $table->text('encrypted_api_key')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'pool_id', 'account_name']);
        });

        Schema::table('mining_devices', function (Blueprint $table) {
            $table->string('agent_token_hash', 64)->nullable()->unique();
            $table->timestamp('last_seen_at')->nullable();
        });

        Schema::table('mining_earnings', function (Blueprint $table) {
            $table->string('external_id')->nullable();
            $table->unique(['user_id', 'pool_id', 'date', 'currency'], 'mining_earnings_daily_unique');
        });

        // Phase 2 – billing
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('renewal_invoiced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('renewal_invoiced_at'));
        Schema::table('mining_earnings', function (Blueprint $t) {
            $t->dropUnique('mining_earnings_daily_unique');
            $t->dropColumn('external_id');
        });
        Schema::table('mining_devices', function (Blueprint $t) {
            $t->dropUnique(['agent_token_hash']);
            $t->dropColumn(['agent_token_hash', 'last_seen_at']);
        });
        Schema::dropIfExists('mining_pool_accounts');
        Schema::table('mining_pools', fn (Blueprint $t) => $t->dropColumn(['api_type', 'api_url']));
        Schema::dropIfExists('bot_signals');
        Schema::table('trading_bots', fn (Blueprint $t) => $t->dropColumn(['state', 'last_run_at', 'last_error']));
        Schema::table('signal_executions', fn (Blueprint $t) => $t->dropColumn('reason'));
        Schema::dropIfExists('signal_subscriptions');
        Schema::table('telegram_signals', function (Blueprint $t) {
            $t->dropUnique(['channel_id', 'message_id']);
            $t->dropColumn(['message_id', 'market_type', 'leverage', 'expires_at']);
        });
        Schema::table('telegram_channels', fn (Blueprint $t) => $t->dropColumn(['webhook_secret', 'signal_ttl_minutes']));
        Schema::dropIfExists('price_alerts');
        Schema::dropIfExists('market_candles');
    }
};
