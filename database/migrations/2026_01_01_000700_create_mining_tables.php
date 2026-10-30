<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mining_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('endpoint');
            $table->string('algorithm', 40);
            $table->string('coin', 10);
            $table->decimal('fee_percent', 6, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('mining_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_id')->nullable()->constrained('mining_pools')->nullOnDelete();
            $table->string('name');
            $table->string('device_type', 20); // asic, gpu, cpu
            $table->string('model')->nullable();
            $table->unsignedInteger('power_watts')->default(0);
            $table->decimal('electricity_rate', 12, 6)->default(0); // cost per kWh
            $table->string('status', 20)->default('offline');
            $table->timestamps();
        });

        Schema::create('mining_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mining_devices')->cascadeOnDelete();
            $table->string('worker_name');
            $table->string('status', 20)->default('offline')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mining_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('mining_workers')->cascadeOnDelete();
            $table->decimal('hashrate', 30, 4); // H/s
            $table->decimal('temperature', 6, 2)->nullable();
            $table->unsignedInteger('accepted_shares')->default(0);
            $table->unsignedInteger('rejected_shares')->default(0);
            $table->timestamp('recorded_at')->index();
        });

        Schema::create('mining_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_id')->nullable()->constrained('mining_pools')->nullOnDelete();
            $table->decimal('amount', 36, 18);
            $table->string('currency', 10);
            $table->date('date');
            $table->timestamps();
            $table->index(['user_id', 'date']);
        });

        Schema::create('mining_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mining_devices')->cascadeOnDelete();
            $table->decimal('electricity_cost', 20, 8)->default(0);
            $table->decimal('pool_fees', 20, 8)->default(0);
            $table->decimal('other_costs', 20, 8)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->date('date');
            $table->timestamps();
        });

        Schema::create('mining_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_id')->nullable()->constrained('mining_pools')->nullOnDelete();
            $table->decimal('amount', 36, 18);
            $table->string('currency', 10);
            $table->string('transaction_hash')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mining_profitability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('mining_devices')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('revenue', 20, 8);
            $table->decimal('expenses', 20, 8);
            $table->decimal('net_profit', 20, 8);
            $table->string('currency', 10)->default('USD');
            $table->timestamps();
            $table->unique(['device_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mining_profitability');
        Schema::dropIfExists('mining_payouts');
        Schema::dropIfExists('mining_expenses');
        Schema::dropIfExists('mining_earnings');
        Schema::dropIfExists('mining_stats');
        Schema::dropIfExists('mining_workers');
        Schema::dropIfExists('mining_devices');
        Schema::dropIfExists('mining_pools');
    }
};
