<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
    }
};
