<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_strategies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('strategy_type', 30)->index(); // grid, dca, scalping, trend, rsi, macd, telegram_signal, manual
            $table->text('description')->nullable();
            $table->json('configuration')->nullable(); // default parameter schema
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('trading_bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->constrained('bot_strategies');
            $table->foreignId('symbol_id')->constrained('trading_symbols');
            $table->string('name');
            $table->string('market', 10); // SPOT, FUTURES, FOREX
            $table->string('mode', 10)->default('paper');
            $table->string('execution_mode', 20)->default('semi_automatic'); // semi_automatic, fully_automatic
            $table->string('status', 20)->default('stopped')->index(); // stopped, running, paused, error
            $table->timestamps();
        });

        Schema::create('bot_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('trading_bots')->cascadeOnDelete();
            $table->json('parameters');
            $table->json('risk_settings')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('bot_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('trading_bots')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable();
            $table->string('status', 20); // running, completed, stopped, failed
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('bot_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('trading_bots')->cascadeOnDelete();
            $table->foreignId('bot_run_id')->nullable()->constrained('bot_runs')->nullOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('execution_status', 20);
            $table->timestamps();
        });

        Schema::create('bot_performance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('trading_bots')->cascadeOnDelete();
            $table->date('period_date');
            $table->decimal('profit', 36, 18)->default(0);
            $table->decimal('drawdown', 12, 4)->default(0);
            $table->decimal('win_rate', 8, 4)->default(0);
            $table->unsignedInteger('trades_count')->default(0);
            $table->timestamps();
            $table->unique(['bot_id', 'period_date']);
        });

        Schema::create('bot_backtests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained('bot_strategies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('symbol_id')->constrained('trading_symbols');
            $table->json('parameters')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('queued');
            $table->json('results')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_backtests');
        Schema::dropIfExists('bot_performance');
        Schema::dropIfExists('bot_orders');
        Schema::dropIfExists('bot_runs');
        Schema::dropIfExists('bot_configurations');
        Schema::dropIfExists('trading_bots');
        Schema::dropIfExists('bot_strategies');
    }
};
