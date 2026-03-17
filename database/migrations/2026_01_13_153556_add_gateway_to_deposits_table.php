<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (!Schema::hasColumn('deposits', 'gateway')) {
                $table->string('gateway')
                      ->nullable()
                      ->after('amount_kobo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (Schema::hasColumn('deposits', 'gateway')) {
                $table->dropColumn('gateway');
            }
        });
    }
};