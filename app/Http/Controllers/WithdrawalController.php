<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Withdrawal;
use App\Models\LedgerEntry;
use App\Notifications\WithdrawalConfirmed;
use App\Notifications\WithdrawalFailedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class WithdrawalController extends Controller
{
    /**
     * Handle user withdrawal request
     */
    public function requestWithdrawal(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            // 100000 kobo = ₦1,000
            'amount' => 'required|integer|min:500000',
        ]);

        if ($user->balance_kobo < $request->amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        if (!$user->account_number || !$user->bank_code) {
            return response()->json(['error' => 'Bank details are missing'], 400);
        }

        try {
            $referenceCode = 'WD-' . now()->format('Ymd-His') . '-' . Str::random(6);

            Log::info('User balance before withdrawal', [
                'user_id'           => $user->id,
                'balance_kobo'      => $user->balance_kobo,
                'withdrawal_amount' => $request->amount,
            ]);

            DB::transaction(function () use ($user, $request, $referenceCode) {
                $user->decrement('balance_kobo', $request->amount);

                // Record in ledger
                LedgerEntry::create([
                    'uid'           => $user->id,
                    'type'          => 'withdrawal',
                    'amount_kobo'   => $request->amount,
                    'balance_after' => $user->balance_kobo - $request->amount,
                    'reference'     => $referenceCode,
                ]);

                Withdrawal::create([
                    'user_id'     => $user->id,
                    'amount_kobo' => $request->amount,
                    'status'      => 'pending',
                    'reference'   => $referenceCode,
                ]);
            });

            Log::info('User balance after withdrawal', [
                'user_id'      => $user->id,
                'balance_kobo' => $user->fresh()->balance_kobo,
            ]);

            if (config('services.paystack.test_mode')) {
                return $this->simulateTestWithdrawal($referenceCode);
            }

            $this->initiatePaystackTransfer($user, $request->amount, $referenceCode);

            return response()->json([
                'message'   => 'Withdrawal request initiated',
                'reference' => $referenceCode,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Withdrawal request failed', [
                'error'   => $e->getMessage(),
                'user_id' => $user->id,
                'amount'  => $request->amount,
            ]);

            return response()->json(['error' => 'Failed to initiate withdrawal'], 500);
        }
    }

    /**
     * Simulate withdrawal in test mode
     */
    protected function simulateTestWithdrawal($referenceCode)
    {
        Log::info('Simulating test mode withdrawal', ['reference' => $referenceCode]);

        $this->handlePaystackCallback(new Request([
            'data' => [
                'reference' => $referenceCode,
                'status'    => 'success',
            ],
        ]));

        return response()->json([
            'message'   => 'Test mode withdrawal successful',
            'reference' => $referenceCode,
            'status'    => 'success',
        ], 200);
    }

    /**
     * Initiate Paystack transfer
     */
    protected function initiatePaystackTransfer($user, int $amountKobo, $referenceCode)
    {
        Log::info('Initiating transfer with Paystack', [
            'account_number' => $user->account_number,
            'bank_code'      => $user->bank_code,
            'amount_kobo'    => $amountKobo,
            'reference'      => $referenceCode,
        ]);

        $recipientCode = $this->getOrCreatePaystackRecipient($user);

        $transferResponse = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transfer', [
                'source'    => 'balance',
                'amount'    => $amountKobo, 
                'recipient' => $recipientCode,
                'reference' => $referenceCode,
            ]);

        if (!$transferResponse->successful()) {
            throw new \Exception('Transfer initiation failed: ' . $transferResponse->json()['message']);
        }
    }

    protected function getOrCreatePaystackRecipient($user)
    {
        return $user->recipient_code ?? $this->createPaystackRecipient($user);
    }

    protected function createPaystackRecipient($user)
    {
        $recipientResponse = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transferrecipient', [
                'type'           => 'nuban',
                'name'           => $user->name,
                'account_number' => $user->account_number,
                'bank_code'      => $user->bank_code,
                'currency'       => 'NGN',
            ]);

        if (!$recipientResponse->successful()) {
            throw new \Exception('Failed to create transfer recipient: ' . $recipientResponse->json()['message']);
        }

        $recipientCode = $recipientResponse->json()['data']['recipient_code'];
        $user->update(['recipient_code' => $recipientCode]);

        return $recipientCode;
    }

    /**
     * Handle Paystack webhook callback
     */
    public function handlePaystackCallback(Request $request)
    {
        if (!config('services.paystack.test_mode')) {
            $payload   = file_get_contents('php://input');
            $signature = $request->header('x-paystack-signature');

            if (!hash_equals(hash_hmac('sha512', $payload, config('services.paystack.webhook_secret')), $signature)) {
                Log::warning('Invalid Paystack webhook signature');
                abort(403, 'Unauthorized webhook');
            }
        }

        $reference = $request->input('data.reference');
        $status    = $request->input('data.status');

        $withdrawal = Withdrawal::firstWhere('reference', $reference);

        if (!$withdrawal) {
            return response()->json(['error' => 'Withdrawal not found'], 404);
        }

        DB::transaction(function () use ($withdrawal, $status) {
            if ($withdrawal->status === 'completed') {
                return;
            }

            if ($status === 'success') {
                $withdrawal->update(['status' => 'completed']);

                try {
                    $withdrawal->user->notify(new WithdrawalConfirmed($withdrawal));
                } catch (\Exception $e) {
                    Log::warning('Withdrawal succeeded but notification failed', [
                        'error'   => $e->getMessage(),
                        'user_id' => $withdrawal->user->id,
                    ]);
                }

            } elseif ($status === 'failed') {
                $withdrawal->user->increment('balance_kobo', $withdrawal->amount_kobo);

                // Restore in ledger
                LedgerEntry::create([
                    'uid'           => $withdrawal->user->id,
                    'type'          => 'withdrawal_reversal',
                    'amount_kobo'   => $withdrawal->amount_kobo,
                    'balance_after' => $withdrawal->user->fresh()->balance_kobo,
                    'reference'     => $withdrawal->reference . '-REVERSAL',
                ]);

                $withdrawal->update(['status' => 'failed']);

                try {
                    $withdrawal->user->notify(new WithdrawalFailedNotification($withdrawal));
                } catch (\Exception $e) {
                    Log::warning('Withdrawal failed and notification could not be sent', [
                        'error'   => $e->getMessage(),
                        'user_id' => $withdrawal->user->id,
                    ]);
                }

            } else {
                Log::warning('Unexpected Paystack withdrawal status', ['status' => $status]);
            }
        });

        return response()->json(['status' => 'Callback handled']);
    }

    /**
     * Check withdrawal status
     */
    public function getWithdrawalStatus($reference)
    {
        $withdrawal = Withdrawal::firstWhere('reference', $reference);

        if (!$withdrawal) {
            return response()->json(['error' => 'Withdrawal not found'], 404);
        }

        return response()->json([
            'status'       => $withdrawal->status,
            'amount'       => $withdrawal->amount_kobo / 100, 
            'amount_kobo'  => $withdrawal->amount_kobo,
            'requested_at' => $withdrawal->created_at,
            'completed_at' => $withdrawal->updated_at,
        ], 200);
    }

    /**
     * Retry all pending withdrawals
     */
    public function retryPendingWithdrawals()
    {
        $pendingWithdrawals = Withdrawal::where('status', 'pending')->get();

        if ($pendingWithdrawals->isEmpty()) {
            return response()->json(['message' => 'No pending withdrawals to retry'], 200);
        }

        foreach ($pendingWithdrawals as $withdrawal) {
            if (!$withdrawal->reference) {
                Log::error('Skipping withdrawal due to missing reference', [
                    'withdrawal_id' => $withdrawal->id,
                ]);
                continue;
            }

            try {
                $this->initiatePaystackTransfer($withdrawal->user, $withdrawal->amount_kobo, $withdrawal->reference);
                Log::info('Withdrawal retried successfully', ['reference' => $withdrawal->reference]);
            } catch (\Exception $e) {
                Log::error('Failed to retry withdrawal', [
                    'withdrawal_id' => $withdrawal->id,
                    'reference'     => $withdrawal->reference,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['message' => 'Pending withdrawals retried'], 200);
    }
}