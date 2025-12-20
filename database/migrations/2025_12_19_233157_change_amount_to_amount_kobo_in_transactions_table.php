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
        Schema::table('transactions', function (Blueprint $table) {
          // Rename column and change type to integer
            $table->renameColumn('amount', 'amount_kobo');

            // Change column type to integer
            $table->integer('amount_kobo')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
                // Revert column type and name
            $table->decimal('amount', 15, 2)->change();
            $table->renameColumn('amount_kobo', 'amount');
        });
    }
};
