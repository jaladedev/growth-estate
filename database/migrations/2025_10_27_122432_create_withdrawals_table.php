<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('pending'); // pending | completed | failed
            $table->string('reference')->unique(); // WD-YYYYMMDD-HHMMSS-random
            $table->timestamps(); // created_at = requested_at, updated_at = completed_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
