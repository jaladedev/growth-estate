<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Deposit;
use App\Notifications\DepositConfirmed;
use App\Notifications\DepositFailedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
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

        // Convert to kobo (INTEGER)
        $amountKobo = (int) ($request->amount * 100);

        $reference = 'DEP-' . Str::uuid();

        // Create deposit record 
        $deposit = Deposit::create([
            'user_id'     => $user->id,
            'reference'   => $reference,
            'amount_kobo' => $amountKobo,
            'status'      => 'pending',
        ]);

        $callbackUrl = URL::signedRoute('deposit.callback', [
            'reference' => $reference
        ]);

        $response = Http::withToken(config('services.paystack.secret'))
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

    public function handleDepositCallback(Request $request)
    {
        $reference = $request->query('reference');

        return redirect(
            config('app.frontend_url') . "/wallet?reference={$reference}"
        );
    }

    public function verifyDeposit(string $reference): void
    {
        $deposit = Deposit::where('reference', $reference)->first();

        if (! $deposit) {
            Log::warning('Deposit not found', ['reference' => $reference]);
            return;
        }

        // Idempotency check
        if ($deposit->status === 'completed') {
            return;
        }

        $response = Http::withToken(config('services.paystack.secret'))
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        if (! $response->successful() ||
            $response['data']['status'] !== 'success') {

            $deposit->update(['status' => 'failed']);
            return;
        }

        $paidAmount = (int) $response['data']['amount'];

        // Amount integrity check
        if ($paidAmount !== $deposit->amount_kobo) {
            Log::critical('Deposit amount mismatch', [
                'reference' => $reference,
                'expected'  => $deposit->amount_kobo,
                'paid'      => $paidAmount,
            ]);

            $deposit->update(['status' => 'failed']);
            return;
        }

        DB::transaction(function () use ($deposit, $paidAmount) {

            // Lock deposit row
            $lockedDeposit = Deposit::where('id', $deposit->id)
                ->lockForUpdate()
                ->first();

            if ($lockedDeposit->status === 'completed') {
                return;
            }

            // Lock user row
            $user = User::where('id', $lockedDeposit->user_id)
                ->lockForUpdate()
                ->first();

            $user->balance_kobo += $paidAmount;
            $user->save();

            $lockedDeposit->status = 'completed';
            $lockedDeposit->completed_at = now();
            $lockedDeposit->save();
        });

        try {
            $deposit->user->notify(
                new DepositConfirmed($paidAmount / 100)
            );
        } catch (\Throwable $e) {
            Log::warning('Deposit email failed', [
                'reference' => $reference,
            ]);
        }
    }
}
