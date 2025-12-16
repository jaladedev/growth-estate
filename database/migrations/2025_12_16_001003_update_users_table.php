<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            // Add balance in minor units
            $table->bigInteger('balance_kobo')
                ->default(0)
                ->after('transaction_pin');

            // Drop decimal balance
            $table->dropColumn('balance');

            // Account safety
            $table->boolean('is_suspended')
                ->default(false)
                ->after('is_admin');

            $table->timestamp('last_transaction_at')
                ->nullable()
                ->after('updated_at');

            // Bank verification flag
            $table->boolean('bank_verified')
                ->default(false)
                ->after('account_name');

            // Indexes
            $table->index(['account_number', 'bank_code']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {

            // Restore balance (only for rollback)
            $table->decimal('balance', 15, 2)
                ->default(0)
                ->after('transaction_pin');

            $table->dropColumn([
                'balance_kobo',
                'is_suspended',
                'last_transaction_at',
                'bank_verified'
            ]);

            $table->dropIndex(['account_number', 'bank_code']);
        });
    }
};
