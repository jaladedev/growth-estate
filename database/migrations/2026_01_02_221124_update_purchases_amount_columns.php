<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Rename columns if needed
        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'total_amount_paid')) {
                $table->renameColumn('total_amount_paid', 'total_amount_paid_kobo');
            }
            if (Schema::hasColumn('purchases', 'total_amount_received')) {
                $table->renameColumn('total_amount_received', 'total_amount_received_kobo');
            }
        });

        // Convert existing values from naira to kobo
        DB::statement('
            UPDATE purchases 
            SET 
                total_amount_paid_kobo = total_amount_paid_kobo * 100,
                total_amount_received_kobo = total_amount_received_kobo * 100
        ');

        // Change column types to unsignedBigInteger (kobo)
        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('total_amount_paid_kobo')
                ->default(0)
                ->change();

            $table->unsignedBigInteger('total_amount_received_kobo')
                ->default(0)
                ->change();
        });
    }

    public function down(): void
    {
        // Convert back from kobo to naira
        DB::statement('
            UPDATE purchases 
            SET 
                total_amount_paid_kobo = total_amount_paid_kobo / 100,
                total_amount_received_kobo = total_amount_received_kobo / 100
        ');

        // Change column types back to decimal
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('total_amount_paid_kobo', 10, 2)->change();
            $table->decimal('total_amount_received_kobo', 10, 2)->change();
        });

        // Rename columns back to original
        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'total_amount_paid_kobo')) {
                $table->renameColumn('total_amount_paid_kobo', 'total_amount_paid');
            }
            if (Schema::hasColumn('purchases', 'total_amount_received_kobo')) {
                $table->renameColumn('total_amount_received_kobo', 'total_amount_received');
            }
        });
    }
};
