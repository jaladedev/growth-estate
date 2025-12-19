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
        // Verify signature
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

        $data      = $payload['data'];
        $reference = $data['reference'];
        $amountPaid = (int) $data['amount']; // total amount paid (including fee)

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
        if ($amountPaid !== $deposit->total_kobo) {
            Log::critical('Webhook total amount mismatch', [
                'reference' => $reference,
                'expected'  => $deposit->total_kobo,
                'paid'      => $amountPaid,
            ]);

            $deposit->update(['status' => 'failed']);
            return response()->json(['status' => 'amount_mismatch'], 400);
        }

        DB::transaction(function () use ($deposit) {

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

            // Credit only the original deposit amount (excluding fee)
            $user->balance_kobo += $lockedDeposit->amount_kobo;
            $user->save();

            // Mark deposit as completed
            $lockedDeposit->status = 'completed';
            $lockedDeposit->updated_at = now();
            $lockedDeposit->save();

            // Ledger entry for the credited amount
            DB::table('ledger_entries')->insert([
                'uid'       => $user->id,
                'type'          => 'deposit',
                'amount_kobo'   => $lockedDeposit->amount_kobo,
                'balance_after' => $user->balance_kobo,
                'reference'     => $lockedDeposit->reference,
                'created_at'    => now(),
            ]);

            // Ledger entry for transaction fee 
            DB::table('ledger_entries')->insert([
                'uid'       => $user->id,
                'type'          => 'transaction_fee',
                'amount_kobo'   => $lockedDeposit->transaction_fee,
                'balance_after' => $user->balance_kobo,
                'reference'     => $lockedDeposit->reference,
                'created_at'    => now(),
            ]);
        });

        return response()->json(['status' => 'processed']);
    }
}
