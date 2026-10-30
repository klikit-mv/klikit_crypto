<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['exchange_accounts', 'broker_accounts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('status_reason')->nullable()->after('status'));
        }
    }

    public function down(): void
    {
        foreach (['exchange_accounts', 'broker_accounts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('status_reason'));
        }
    }
};
