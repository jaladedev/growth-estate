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
        // Verify Paystack signature
        $signature = $request->header('x-paystack-signature');
        $computed  = hash_hmac(
            'sha512',
            $request->getContent(),
            config('services.paystack.secret_key')
        );

        if (! hash_equals($computed, $signature)) {
            Log::warning('Invalid Paystack signature');
            return response()->json(['status' => 'invalid'], 400);
        }

        $payload = $request->all();

        if ($payload['event'] !== 'charge.success') {
            return response()->json(['status' => 'ignored']);
        }

        $data = $payload['data'];
        $reference = $data['reference'];
        $amountPaid = (int) $data['amount'];

        $deposit = Deposit::where('reference', $reference)->first();

        if (! $deposit) {
            Log::warning('Webhook deposit not found', ['reference' => $reference]);
            return response()->json(['status' => 'not_found']);
        }

        // Idempotency
        if ($deposit->status === 'completed') {
            return response()->json(['status' => 'already_processed']);
        }

        // Amount integrity check
        if ($deposit->amount_kobo !== $amountPaid) {
            Log::critical('Webhook amount mismatch', [
                'reference' => $reference,
                'expected'  => $deposit->amount_kobo,
                'paid'      => $amountPaid,
            ]);

            $deposit->update(['status' => 'failed']);
            return response()->json(['status' => 'amount_mismatch'], 400);
        }

        DB::transaction(function () use ($deposit, $amountPaid) {

            // Lock deposit
            $lockedDeposit = Deposit::where('id', $deposit->id)
                ->lockForUpdate()
                ->first();

            if ($lockedDeposit->status === 'completed') {
                return;
            }

            // Lock user
            $user = User::where('id', $lockedDeposit->user_id)
                ->lockForUpdate()
                ->first();

            // Credit balance
            $user->balance_kobo += $amountPaid;
            $user->save();

            // Mark deposit
            $lockedDeposit->status = 'completed';
            $lockedDeposit->completed_at = now();
            $lockedDeposit->save();

            DB::table('ledger_entries')->insert([
                'user_id'        => $user->id,
                'type'           => 'deposit',
                'amount_kobo'    => $amountPaid,
                'balance_after'  => $user->balance_kobo,
                'reference'      => $lockedDeposit->reference,
                'created_at'     => now(),
            ]);
        });

        return response()->json(['status' => 'processed']);
    }
}
