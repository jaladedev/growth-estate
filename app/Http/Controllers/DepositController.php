<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class DepositController extends Controller
{
    /**
     * Initiate deposit
     */
   public function initiateDeposit(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        // Original amount in kobo
        $amountKobo = (int) ($request->amount * 100);

        // Add 2% transaction fee
        $transactionFee = (int) round($amountKobo * 0.02); // 2% of amount
        $totalAmountKobo = $amountKobo + $transactionFee;

        $reference  = 'DEP-' . Str::uuid();

        Deposit::create([
            'user_id'        => $user->id,
            'reference'      => $reference,
            'amount_kobo'    => $amountKobo,       
            'total_kobo'     => $totalAmountKobo,  
            'transaction_fee'=> $transactionFee,
            'status'         => 'pending',
        ]);

        $callbackUrl = config('app.frontend_url') . "/wallet?reference={$reference}";

        $response = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transaction/initialize', [
                'email'        => $user->email,
                'amount'       => $totalAmountKobo, 
                'reference'    => $reference,
                'callback_url' => $callbackUrl,
            ]);

        if (! $response->successful()) {
            Log::error('Deposit init failed', [
                'reference' => $reference,
                'response'  => $response->json(),
            ]);

            return response()->json([
                'error' => 'Unable to initialize deposit'
            ], 500);
        }

        return response()->json([
            'payment_url' => $response['data']['authorization_url'],
            'reference'   => $reference,
            'transaction_fee' => $transactionFee / 100, 
            'total_amount' => $totalAmountKobo / 100,
        ]);
    }


    /**
     * Frontend status check 
     */
    public function verifyDeposit(string $reference)
    {
        $deposit = Deposit::where('reference', $reference)->first();

        if (! $deposit) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json([
            'reference' => $deposit->reference,
            'status'    => $deposit->status,
            'amount'    => $deposit->amount_kobo / 100,
        ]);
    }
}
