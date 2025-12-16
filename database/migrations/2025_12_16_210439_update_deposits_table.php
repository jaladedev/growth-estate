<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('s', function (Blueprint $table) {
            if (Schema::hasColumn('deposits', 'amount')) {
                $table->renameColumn('amount', 'amount_kobo');
            }

        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            // Revert changes safely
            if (Schema::hasColumn('deposits', 'amount_kobo')) {
                $table->renameColumn('amount_kobo', 'amount');
            }
        });
    }
};
