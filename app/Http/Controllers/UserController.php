<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Land;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\Deposit;
use App\Models\Withdrawal;

class UserController extends Controller
{
    // Retrieve lands and units owned by the user
    public function getUserUnitsForLand(Request $request, $landId)
    {
        try {
            // Get the authenticated user from the JWT token
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return response()->json(['error' => 'User not authenticated'], 401);
        }

        // Query the purchases table for the user's units for the specific land
        $purchase = Purchase::where('user_id', $user->id)
                            ->where('land_id', $landId)
                            ->first();

        // Logging if desired
        Log::info("User {$user->id} queried units for land {$landId}", [
            'units_owned' => $purchase ? $purchase->units : 0,
        ]);

        return response()->json([
            'land_id' => $landId,
            'units_owned' => $purchase ? $purchase->units : 0,
        ]);
    }

    // Retrieve all lands and units owned by the user
    public function getAllUserLands(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return response()->json(['error' => 'User not authenticated'], 401);
        }

        // Fetch all lands and units the user owns from purchases
        $purchases = Purchase::with('land')
                             ->where('user_id', $user->id)
                             ->get();

        // Prepare response data
        $ownedLands = $purchases->map(function ($purchase) {
            return [
                'land_id' => $purchase->land->id,
                'land_name' => $purchase->land->title,
                'units_owned' => $purchase->units,
            ];
        });

        return response()->json(['owned_lands' => $ownedLands]);
    }

      // Update user's bank details using Paystack API
      public function updateBankDetails(Request $request)
      {
          $user = JWTAuth::parseToken()->authenticate();
      
          // Validate the incoming data
          $request->validate([
              'account_number' => 'required|numeric|digits_between:10,12', // Adjust length for your region
              'bank_name' => 'required|string',
          ]);
      
          try {
              // Step 1: Fetch bank codes from Paystack (Cache for efficiency)
              $banks = Cache::remember('paystack_banks', now()->addHours(12), function () {
                  $response = Http::withToken(config('services.paystack.secret_key'))
                      ->get('https://api.paystack.co/bank');
      
                  return $response->successful() ? $response->json()['data'] : [];
              });
      
              $bank = collect($banks)->firstWhere('name', $request->bank_name);
      
              if (!$bank) {
                  return response()->json(['error' => 'Invalid bank name provided'], 400);
              }
      
              $bankCode = $bank['code'];
      
              // Step 2: Resolve account number with Paystack
              $resolveResponse = Http::withToken(config('services.paystack.secret_key'))
                  ->get('https://api.paystack.co/bank/resolve', [
                      'account_number' => $request->account_number,
                      'bank_code' => $bankCode,
                  ]);
      
              if (!$resolveResponse->successful()) {
                  return response()->json([
                      'error' => 'Failed to resolve account number',
                      'details' => $resolveResponse->json(),
                  ], 400);
              }
      
              $resolvedData = $resolveResponse->json();
              if (!isset($resolvedData['data']['account_name'])) {
                  return response()->json(['error' => 'Invalid account details returned'], 400);
              }
      
              $accountName = $resolvedData['data']['account_name'];

              \Log::info('Updating bank details:', [
                'account_number' => $request->account_number,
                'bank_code' => $bankCode,
                'bank_name' => $request->bank_name,
                'account_name' => $accountName,
            ]);
      
              // Step 3: Update the user's bank details
              $user->update([
                  'account_number' => $request->account_number,
                  'bank_code' => $bankCode,
                  'bank_name' => $request->bank_name,
                  'account_name' => $accountName,
              ]);
      
              return response()->json(['message' => 'Bank details updated successfully'], 200);
          } catch (\Exception $e) {
              return response()->json([
                  'error' => 'An error occurred while updating bank details',
                  'message' => $e->getMessage(),
              ], 500);
          }
}

    public function getUserStats()
{
    try {
        $user = JWTAuth::parseToken()->authenticate();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        Log::info('User found', ['id' => $user->id]);

        $landsOwned = $user->purchases()->distinct('land_id')->count('land_id');
        $unitsOwned = $user->purchases()->sum('units');
        $totalInvested = $user->purchases()->sum('total_amount_paid');

        // If user->withdrawals() is not defined, this line will crash:
        $totalWithdrawn = method_exists($user, 'withdrawals')
            ? $user->withdrawals()->where('status', 'completed')->sum('amount')
            : 0;

        $pendingWithdrawals = method_exists($user, 'withdrawals')
            ? $user->withdrawals()->where('status', 'pending')->count()
            : 0;

        $balance = $user->balance ?? 0;

        return response()->json([
            'success' => true,
            'data' => [
                'lands_owned' => $landsOwned,
                'units_owned' => $unitsOwned,
                'total_invested' => $totalInvested,
                'total_withdrawn' => $totalWithdrawn,
                'pending_withdrawals' => $pendingWithdrawals,
                'balance' => $balance,
            ],
        ]);
    } catch (\Exception $e) {
        Log::error('getUserStats error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

        return response()->json([
            'success' => false,
            'message' => 'Error fetching stats',
            'error' => $e->getMessage(),
        ], 500);
    }
}

    public function getUserTransactions()
{
    try {
        $user = JWTAuth::parseToken()->authenticate();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $deposits = Deposit::where('user_id', $user->id)
            ->select('id', 'amount', 'status', 'created_at')
            ->get()
            ->map(fn($d) => [
                'type' => 'Deposit',
                'amount' => $d->amount,
                'status' => ucfirst($d->status),
                'date' => $d->created_at->toIso8601String(),
            ]);

        $withdrawals = Withdrawal::where('user_id', $user->id)
            ->select('id', 'amount', 'status', 'created_at')
            ->get()
            ->map(fn($w) => [
                'type' => 'Withdrawal',
                'amount' => $w->amount,
                'status' => ucfirst($w->status),
                'date' => $w->created_at->toIso8601String(),
            ]);

        $purchases = Purchase::where('user_id', $user->id)
            ->select('id', 'land_id', 'total_amount_paid', 'units', 'purchase_date')
            ->with('land:id,title')
            ->get()
            ->map(fn($p) => [
                'type' => 'Purchase',
                'land' => $p->land->title ?? 'Unknown Land',
                'amount' => $p->total_amount_paid,
                'units' => $p->units,
                'status' => 'Success',
                'date' => $p->purchase_date ?? $p->created_at->toIso8601String(),
            ]);

        $transactions = collect()
            ->merge($deposits)
            ->merge($withdrawals)
            ->merge($purchases)
            ->sortByDesc('date')
            ->values();

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error fetching transactions',
            'error' => $e->getMessage(),
        ], 500);
    }
}

}