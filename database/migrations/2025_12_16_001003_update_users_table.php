<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            // Only modify if needed
            $table->unsignedBigInteger('balance_kobo')
                ->default(0)
                ->change();

            if (Schema::hasColumn('users', 'balance')) {
                $table->dropColumn('balance');
            }

            if (!Schema::hasColumn('users', 'is_suspended')) {
                $table->boolean('is_suspended')
                    ->default(false)
                    ->after('is_admin');
            }

            if (!Schema::hasColumn('users', 'last_transaction_at')) {
                $table->timestamp('last_transaction_at')
                    ->nullable()
                    ->after('updated_at');
            }

            if (!Schema::hasColumn('users', 'bank_verified')) {
                $table->boolean('bank_verified')
                    ->default(false)
                    ->after('account_name');
            }

            // Index (safe check)
            $table->index(['account_number', 'bank_code']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {

            // Restore old balance only if rolling back
            if (!Schema::hasColumn('users', 'balance')) {
                $table->decimal('balance', 15, 2)
                    ->default(0);
            }

            $table->dropColumn([
                'is_suspended',
                'last_transaction_at',
                'bank_verified',
            ]);

            $table->dropIndex(['account_number', 'bank_code']);
        });
    }
};
