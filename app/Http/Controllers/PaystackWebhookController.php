<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function handle(Request $request)
    {

        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            Log::warning('Missing Paystack signature');
            return response()->json(['status' => 'invalid'], 400);
        }

        $computed = hash_hmac(
            'sha512',
            $request->getContent(),
            config('services.paystack.secret_key')
        );

        if (! hash_equals($computed, $signature)) {
            Log::warning('Invalid Paystack signature');
            return response()->json(['status' => 'invalid'], 400);
        }

        $payload = $request->all();

        if (
            ($payload['event'] ?? null) !== 'charge.success' ||
            ! isset($payload['data']['reference'], $payload['data']['amount'])
        ) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $reference  = $payload['data']['reference'];
        $amountPaid = (int) $payload['data']['amount'];

        $deposit = Deposit::where('reference', $reference)->first();

        if (! $deposit) {
            Log::warning('Webhook deposit not found', ['reference' => $reference]);
            return response()->json(['status' => 'logged'], 200);
        }

        // Idempotency
        if ($deposit->status === 'completed') {
            return response()->json(['status' => 'already_processed'], 200);
        }

        // Amount integrity check
        if ($deposit->amount_kobo !== $amountPaid) {
            Log::critical('Webhook amount mismatch', [
                'reference' => $reference,
                'expected'  => $deposit->amount_kobo,
                'paid'      => $amountPaid,
            ]);

            $deposit->update(['status' => 'failed']);
            return response()->json(['status' => 'logged'], 200);
        }

        DB::transaction(function () use ($deposit, $amountPaid) {

            $lockedDeposit = Deposit::where('id', $deposit->id)
                ->lockForUpdate()
                ->first();

            if ($lockedDeposit->status === 'completed') {
                return;
            }

            $user = User::where('id', $lockedDeposit->user_id)
                ->lockForUpdate()
                ->first();

            $user->balance_kobo += $amountPaid;
            $user->save();

            $lockedDeposit->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            DB::table('ledger_entries')->insert([
                'uid'       => $user->id,
                'type'          => 'deposit',
                'amount_kobo'   => $amountPaid,
                'balance_after'=> $user->balance_kobo,
                'reference'     => $lockedDeposit->reference,
                'created_at'    => now(),
            ]);
        });

        return response()->json(['status' => 'processed'], 200);
    }
}
