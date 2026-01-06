<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        /**
         * Step 1: Add amount_kobo safely
         */
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'amount_kobo')) {
                $table->unsignedBigInteger('amount_kobo')->default(0);
            }
        });

        /**
         * Step 2: Copy data (decimal → kobo)
         * Works in SQLite & MySQL
         */
        if (Schema::hasColumn('transactions', 'amount')) {
            DB::table('transactions')->update([
                'amount_kobo' => DB::raw('amount * 100')
            ]);
        }

        /**
         * Step 3: Drop old column (safe)
         */
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'amount')) {
                $table->dropColumn('amount');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        /**
         * Step 1: Restore amount column
         */
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'amount')) {
                $table->decimal('amount', 15, 2)->default(0);
            }
        });

        /**
         * Step 2: Copy data back (kobo → decimal)
         */
        if (Schema::hasColumn('transactions', 'amount_kobo')) {
            DB::table('transactions')->update([
                'amount' => DB::raw('amount_kobo / 100')
            ]);
        }

        /**
         * Step 3: Drop kobo column
         */
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'amount_kobo')) {
                $table->dropColumn('amount_kobo');
            }
        });
    }
};
