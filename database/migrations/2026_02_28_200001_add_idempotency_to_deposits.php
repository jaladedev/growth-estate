<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix #2 — Deposit idempotency.
 *
 * Paystack fires charge.success webhooks multiple times on retries.
 * Without a guard, each retry credits the user's balance again.
 *
 * Two-pronged defence:
 *  1. `processed_at` timestamp — set once when we first process a webhook.
 *     Code checks this before touching any balances.
 *  2. Unique index on `reference` — makes duplicate inserts impossible at
 *     the DB level if two webhook deliveries race past the application check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->after('status');
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn('processed_at');
            $table->dropUnique(['reference']);
        });
    }
};
