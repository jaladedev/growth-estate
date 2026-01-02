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
        Schema::table('deposits', function (Blueprint $table) {
            // Only change if the column exists and is not already unsignedBigInteger
            if (Schema::hasColumn('deposits', 'amount_kobo')) {
                $columnType = DB::selectOne("
                    SELECT DATA_TYPE 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE table_name = 'deposits' 
                      AND COLUMN_NAME = 'amount_kobo' 
                      AND TABLE_SCHEMA = DATABASE()
                ")->DATA_TYPE ?? null;

                if ($columnType !== 'bigint') {
                    $table->unsignedBigInteger('amount_kobo')->change();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            // Revert back to decimal only if column exists
            if (Schema::hasColumn('deposits', 'amount_kobo')) {
                $table->decimal('amount_kobo', 12, 2)->change();
            }
        });
    }
};
