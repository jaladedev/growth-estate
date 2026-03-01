<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Services\Payments\DepositService;
use App\Services\Payments\MonnifyService;
use App\Services\Payments\PaystackService;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class DepositController extends Controller
{
    public function initiateDeposit(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'amount'  => 'required|integer|min:10000|max:1000000000',
            'gateway' => 'required|in:paystack,monnify',
        ]);

        // amount is already in kobo — pass directly to service
        $deposit = DepositService::createDepositKobo(
            $user,
            $request->amount,
            $request->gateway
        );

        $callbackUrl = config('app.frontend_url') . "/wallet?reference={$deposit->reference}";

        if ($deposit->gateway === 'paystack') {
            $response = PaystackService::initialize(
                $user->email,
                $deposit->total_kobo,
                $deposit->reference,
                $callbackUrl
            );

            $paymentUrl = $response['data']['authorization_url'] ?? null;

        } else { // monnify
            $response = MonnifyService::initialize(
                $user->email,
                $deposit->reference,
                $deposit->total_kobo,
                $callbackUrl,
                $user->name
            );

            $paymentUrl = $response['responseBody']['checkoutUrl'] ?? null;
        }

        if (! $paymentUrl) {
            return response()->json(['error' => 'Payment initialization failed.'], 500);
        }

        return response()->json([
            'payment_url'     => $paymentUrl,
            'reference'       => $deposit->reference,
            'gateway'         => $deposit->gateway,
            'transaction_fee' => $deposit->transaction_fee / 100,
            'total_amount'    => $deposit->total_kobo / 100,
        ]);
    }

    public function verifyDeposit(string $reference)
    {
        $deposit = Deposit::where('reference', $reference)->first();

        if (! $deposit) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json([
            'reference' => $deposit->reference,
            'gateway'   => $deposit->gateway,
            'status'    => $deposit->status,
            'amount'    => $deposit->amount_kobo / 100,
        ]);
    }
}