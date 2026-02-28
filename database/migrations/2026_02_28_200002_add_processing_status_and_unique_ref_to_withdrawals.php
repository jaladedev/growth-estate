<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix #4 (withdrawal limits) + Fix #5 (retry idempotency).
 *
 * Adds:
 *  - `processing` status to withdrawals so retryPendingWithdrawals()
 *    can mark rows as in-flight before calling Paystack, preventing
 *    double-payout if the job runs concurrently.
 *  - Unique index on `reference` — DB-level duplicate guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: add 'processing' to the withdrawals status enum
        \DB::statement("ALTER TYPE withdrawals_status_enum ADD VALUE IF NOT EXISTS 'processing'");

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropUnique(['reference']);
        });
        // Cannot remove enum values in PostgreSQL without recreating the type
    }
};
