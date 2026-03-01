<?php

namespace App\Services\Payments;

use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Str;

class DepositService
{
    public const FEE_PERCENT = 2;

    /**
     * Create a deposit record.
     *
     * @param  User    $user
     * @param  int     $amountKobo  Amount already in kobo (e.g. 10000 = ₦100)
     * @param  string  $gateway
     * @return Deposit
     */
    public static function createDepositKobo(User $user, int $amountKobo, string $gateway): Deposit
    {
        // Calculate fee (2%)
        $feeKobo = (int) round($amountKobo * (self::FEE_PERCENT / 100));

        // Total payable
        $totalKobo = $amountKobo + $feeKobo;

        return Deposit::create([
            'user_id'         => $user->id,
            'reference'       => 'DEP-' . Str::uuid(),
            'amount_kobo'     => $amountKobo,
            'gateway'         => $gateway,
            'transaction_fee' => $feeKobo,
            'total_kobo'      => $totalKobo,
            'status'          => 'pending',
        ]);
    }

    /**
     * Convenience wrapper that accepts naira and converts to kobo.
     * Prefer createDepositKobo() for all new call sites.
     *
     * @deprecated Use createDepositKobo() directly.
     */
    public static function createDeposit(User $user, int $amountNaira, string $gateway): Deposit
    {
        return self::createDepositKobo($user, $amountNaira * 100, $gateway);
    }
}