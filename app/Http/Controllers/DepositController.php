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

        $amountKobo = (int) ($request->amount * 100);
        $reference  = 'DEP-' . Str::uuid();

        Deposit::create([
            'user_id'     => $user->id,
            'reference'   => $reference,
            'amount_kobo' => $amountKobo,
            'status'      => 'pending',
        ]);

        $callbackUrl = config('app.frontend_url') . "/wallet?reference={$reference}";

        $response = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transaction/initialize', [
                'email'        => $user->email,
                'amount'       => $amountKobo,
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
