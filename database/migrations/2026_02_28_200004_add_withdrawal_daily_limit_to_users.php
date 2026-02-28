<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix #10 — Per-user daily withdrawal limit tracking.
 *
 * Adds a `withdrawal_daily_total_kobo` and `withdrawal_day` column to users
 * so WithdrawalController can enforce a daily cap without an extra table.
 * Resets automatically when `withdrawal_day` is not today.
 *
 * Default cap: ₦500,000/day (50,000,000 kobo). Configurable via
 * WITHDRAWAL_DAILY_LIMIT_KOBO in .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('withdrawal_daily_total_kobo')->default(0)->after('rewards_balance_kobo');
            $table->date('withdrawal_day')->nullable()->after('withdrawal_daily_total_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['withdrawal_daily_total_kobo', 'withdrawal_day']);
        });
    }
};
