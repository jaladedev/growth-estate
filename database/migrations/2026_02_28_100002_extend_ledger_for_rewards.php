<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: add new enum values (IF NOT EXISTS prevents duplicate errors)
         DB::statement("ALTER TYPE ledger_entries_type_enum ADD VALUE IF NOT EXISTS 'withdrawal_reversal'");
        DB::statement("ALTER TYPE ledger_entries_type_enum ADD VALUE IF NOT EXISTS 'reward_credit'");
        DB::statement("ALTER TYPE ledger_entries_type_enum ADD VALUE IF NOT EXISTS 'reward_spend'");

        Schema::table('ledger_entries', function (Blueprint $table) {
            // Null for non-rewards rows; populated for reward_credit / reward_spend rows
            $table->bigInteger('rewards_balance_after')->nullable()->after('balance_after');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn('rewards_balance_after');
        });
    }
};