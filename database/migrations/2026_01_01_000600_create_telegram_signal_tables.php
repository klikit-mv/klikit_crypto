<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // null = platform bot
            $table->string('username')->nullable();
            $table->text('bot_token_encrypted');
            $table->string('status', 20)->default('inactive');
            $table->timestamps();
        });

        Schema::create('telegram_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_bot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel_id', 64);
            $table->string('channel_name');
            $table->string('parser_format', 30)->default('generic');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'channel_id']);
        });

        Schema::create('telegram_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('telegram_channels')->cascadeOnDelete();
            $table->foreignId('symbol_id')->nullable()->constrained('trading_symbols')->nullOnDelete();
            $table->string('symbol', 40);
            $table->string('signal_type', 10); // buy, sell
            $table->decimal('entry_price', 36, 18)->nullable();
            $table->decimal('entry_price_max', 36, 18)->nullable();
            $table->decimal('stop_loss', 36, 18)->nullable();
            $table->string('status', 20)->default('received')->index(); // received, valid, rejected, expired
            $table->string('rejection_reason')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
        });

        Schema::create('signal_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_id')->constrained('telegram_signals')->cascadeOnDelete();
            $table->unsignedTinyInteger('target_number');
            $table->decimal('target_price', 36, 18);
            $table->timestamp('hit_at')->nullable();
            $table->timestamps();
        });

        Schema::create('signal_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_id')->constrained('telegram_signals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending_approval'); // pending_approval, approved, rejected, executed, failed
            $table->timestamps();
            $table->unique(['signal_id', 'user_id']);
        });

        Schema::create('signal_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_id')->nullable()->constrained('telegram_signals')->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('telegram_channels')->nullOnDelete();
            $table->text('raw_message');
            $table->json('parsed_data')->nullable();
            $table->string('result', 20); // parsed, ambiguous, ignored
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_logs');
        Schema::dropIfExists('signal_executions');
        Schema::dropIfExists('signal_targets');
        Schema::dropIfExists('telegram_signals');
        Schema::dropIfExists('telegram_channels');
        Schema::dropIfExists('telegram_bots');
    }
};
