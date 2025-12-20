<?php

namespace App\Services\Payments;

use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Str;

class DepositService
{
    public const FEE_PERCENT = 2;

    /**
     * Create a deposit record
     *
     * @param User   $user
     * @param int    $amount   Amount in naira (integer)
     * @param string $gateway
     * @return Deposit
     */
    public static function createDeposit(User $user, int $amount, string $gateway): Deposit
    {
        // Convert to kobo
        $amountKobo = $amount * 100;

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
}
