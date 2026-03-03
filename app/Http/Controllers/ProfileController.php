<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Handles user profile and account-level reads.
 * Split from the monolithic UserController.
 *
 * Routes:
 *   GET  /me
 *   PUT  /user/bank-details
 *   GET  /user/stats
 */
class ProfileController extends Controller
{
    // GET /me
    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => $request->user()->makeHidden(['password', 'transaction_pin', 'pin_reset_code']),
        ]);
    }

    // PUT /user/bank-details
    public function updateBankDetails(Request $request)
    {
        $request->validate([
            'bank_code'      => 'required|string|max:10',
            'account_number' => 'required|digits:10',
        ]);

        $user = $request->user();

        // Resolve account name via Paystack
        $resolve = Http::withToken(config('services.paystack.secret_key'))
            ->get('https://api.paystack.co/bank/resolve', [
                'account_number' => $request->account_number,
                'bank_code'      => $request->bank_code,
            ]);

        if ($resolve->failed() || ! $resolve->json('status')) {
            return response()->json([
                'success' => false,
                'message' => 'Could not verify account details. Please check and try again.',
            ], 422);
        }

        $accountName = $resolve->json('data.account_name');

        // Create/update Paystack transfer recipient
        $recipient = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transferrecipient', [
                'type'           => 'nuban',
                'name'           => $accountName,
                'account_number' => $request->account_number,
                'bank_code'      => $request->bank_code,
                'currency'       => 'NGN',
            ]);

        if ($recipient->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to register bank details. Please try again.',
            ], 422);
        }

        $user->update([
            'bank_code'        => $request->bank_code,
            'account_number'   => $request->account_number,
            'account_name'     => $accountName,
            'recipient_code'   => $recipient->json('data.recipient_code'),
            'bank_verified'    => true,
        ]);

        return response()->json([
            'success'      => true,
            'message'      => 'Bank details updated.',
            'account_name' => $accountName,
        ]);
    }

    // GET /user/stats
    public function stats(Request $request)
    {
        $userId = $request->user()->id;

        $totalInvested = Purchase::where('user_id', $userId)->sum('total_amount_paid_kobo');
        $totalReceived = Purchase::where('user_id', $userId)->sum('total_amount_received_kobo');
        $totalUnits    = Purchase::where('user_id', $userId)->sum('units');
        $totalLands    = Purchase::where('user_id', $userId)->where('units', '>', 0)->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_invested_kobo'  => $totalInvested,
                'total_received_kobo'  => $totalReceived,
                'total_units_held'     => $totalUnits,
                'total_lands_invested' => $totalLands,
            ],
        ]);
    }

    // GET /user/lands
    public function lands(Request $request)
    {
        $holdings = Purchase::with('land:id,title,location,size')
            ->where('user_id', $request->user()->id)
            ->where('units', '>', 0)
            ->get(['land_id', 'units', 'total_amount_paid_kobo', 'purchase_date']);

        return response()->json(['success' => true, 'data' => $holdings]);
    }
}