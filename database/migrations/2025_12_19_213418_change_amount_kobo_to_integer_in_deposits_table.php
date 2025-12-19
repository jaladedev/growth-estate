<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeAmountToIntegerInDepositsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            // Change amount to unsignedBigInteger
            $table->unsignedBigInteger('amount_kobo')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            // Revert back to decimal(12,2) if needed
            $table->decimal('amount_kobo', 12, 2)->change();
        });
    }
}
