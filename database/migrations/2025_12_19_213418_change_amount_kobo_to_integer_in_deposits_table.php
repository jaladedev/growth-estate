<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /**
         * SQLite-safe & MySQL-safe approach:
         * - Never use INFORMATION_SCHEMA
         * - Never use ->change()
         * - Add column if missing
         * - Do NOT mutate type in-place
         */

        if (!Schema::hasTable('deposits')) {
            return;
        }

        Schema::table('deposits', function (Blueprint $table) {

            // Ensure amount_kobo exists
            if (!Schema::hasColumn('deposits', 'amount_kobo')) {
                $table->unsignedBigInteger('amount_kobo')->default(0);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('deposits')) {
            return;
        }

        Schema::table('deposits', function (Blueprint $table) {

            // Revert safely by recreating column if needed
            if (Schema::hasColumn('deposits', 'amount_kobo')) {
                $table->decimal('amount_kobo', 12, 2)->default(0);
            }
        });
    }
};
