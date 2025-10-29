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
use App\Models\Transaction;
use App\Mail\TransactionPinResetMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;

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
                'price_per_unit' => $purchase->land->price_per_unit,
                'current_value' => $purchase->units * $purchase->land->price_per_unit           ,
            ];
        });

        return response()->json(['owned_lands' => $ownedLands]);
    }
    
    public function setTransactionPin(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'pin' => 'required|digits:4', // 4-digit PIN
        ]);

        if ($user->transaction_pin) {
            return response()->json(['error' => 'Transaction PIN is already set'], 400);
        }

        // Hash the PIN before storing
        $user->transaction_pin = bcrypt($request->pin);
        $user->save();

        return response()->json(['message' => 'Transaction PIN set successfully']);
    }

    public function updateTransactionPin(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'old_pin' => 'required|digits:4',
            'new_pin' => 'required|digits:4',
        ]);

        // Check old PIN
        if (!password_verify($request->old_pin, $user->transaction_pin)) {
            return response()->json(['error' => 'Old PIN is incorrect'], 400);
        }

        // Update with new PIN
        $user->transaction_pin = bcrypt($request->new_pin);
        $user->save();

        return response()->json(['message' => 'Transaction PIN updated successfully']);
    }

    public function sendPinResetCode(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $code = rand(100000, 999999);

        $user->pin_reset_code = $code;
        $user->pin_reset_expires_at = now()->addMinutes(10);
        $user->save();

        // send email
        Mail::to($user->email)->send(new TransactionPinResetMail($user, $code));

        return response()->json(['message' => 'PIN reset code sent to your email.']);
    }

    public function verifyPinResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|numeric'
        ]);

        $user = User::where('email', $request->email)->first();

        if (
            !$user ||
            $user->pin_reset_code != $request->code ||
            now()->greaterThan($user->pin_reset_expires_at)
        ) {
            return response()->json(['error' => 'Invalid or expired code'], 400);
        }

        return response()->json(['message' => 'Code verified successfully.']);
    }

    public function resetTransactionPin(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|numeric',
            'new_pin' => 'required|digits:4'
        ]);

        $user = User::where('email', $request->email)->first();

        if (
            !$user ||
            $user->pin_reset_code != $request->code ||
            now()->greaterThan($user->pin_reset_expires_at)
        ) {
            return response()->json(['error' => 'Invalid or expired code'], 400);
        }

        $user->transaction_pin = Hash::make($request->new_pin);
        $user->pin_reset_code = null;
        $user->pin_reset_expires_at = null;
        $user->save();

        return response()->json(['message' => 'Transaction PIN reset successfully.']);
    }

    // Update user's bank details using Paystack API
     public function updateBankDetails(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'account_number' => 'required|numeric|digits_between:10,12',
            'bank_name' => 'required|string',
        ]);

        try {
            $banks = $this->getPaystackBanks();

            $bank = collect($banks)->firstWhere('name', $request->bank_name);
            if (!$bank) {
                return response()->json(['error' => 'Invalid bank name provided.'], 400);
            }

            $bankCode = $bank['code'];

            $resolveResponse = Http::withToken(config('services.paystack.secret_key'))
                ->get('https://api.paystack.co/bank/resolve', [
                    'account_number' => $request->account_number,
                    'bank_code' => $bankCode,
                ]);

            if (!$resolveResponse->successful()) {
                return response()->json([
                    'error' => 'Failed to verify account number. Please try again later.',
                    'details' => $resolveResponse->json(),
                ], 400);
            }

            $resolvedData = $resolveResponse->json()['data'] ?? null;
            if (!$resolvedData || empty($resolvedData['account_name'])) {
                return response()->json(['error' => 'Invalid account details returned from Paystack.'], 400);
            }

            $accountName = $resolvedData['account_name'];

            $user->update([
                'account_number' => $request->account_number,
                'bank_code' => $bankCode,
                'bank_name' => $request->bank_name,
                'account_name' => $accountName,
            ]);

            return response()->json([
                'message' => 'Bank details updated successfully.',
                'data' => [
                    'bank_name' => $request->bank_name,
                    'bank_code' => $bankCode,
                    'account_number' => $request->account_number,
                    'account_name' => $accountName,
                ],
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('❌ Bank update error:', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'An unexpected error occurred while updating bank details.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function getBanks()
    {
        try {
            $banks = $this->getPaystackBanks();
            return response()->json(['data' => $banks]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to fetch bank list.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function getPaystackBanks()
    {
        return Cache::remember('paystack_banks', now()->addHours(12), function () {
            $response = Http::withToken(config('services.paystack.secret_key'))
                ->get('https://api.paystack.co/bank');

            if (!$response->successful()) {
                throw new \Exception('Unable to fetch bank list from Paystack.');
            }

            return $response->json()['data'];
        });
    }

    public function resolveAccount(Request $request)
    {
        $request->validate([
            'account_number' => 'required|digits:10',
            'bank_code' => 'required|string',
        ]);

        try {
            $response = Http::withToken(config('services.paystack.secret_key'))
                ->get('https://api.paystack.co/bank/resolve', [
                    'account_number' => $request->account_number,
                    'bank_code' => $request->bank_code,
                ]);

            if (!$response->successful()) {
                // 🔹 Log failure details
                Log::error('Paystack account verification failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'account_number' => $request->account_number,
                    'bank_code' => $request->bank_code,
                ]);

                return response()->json(['error' => 'Verification failed. Please try again later.'], 400);
            }

            Log::info('Paystack account verification successful', [
                'account_number' => $request->account_number,
                'bank_code' => $request->bank_code,
            ]);

            return response()->json($response->json());
        } catch (\Exception $e) {
            Log::error('Error verifying account with Paystack', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_number' => $request->account_number,
                'bank_code' => $request->bank_code,
            ]);

            return response()->json(['error' => 'An unexpected error occurred while verifying your account.'], 500);
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

            $landsOwned = $user->purchases()->where('units', '>', 0)->distinct('land_id')->count('land_id');
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

            // Fetch deposits
            $deposits = Deposit::where('user_id', $user->id)
                ->select('id', 'amount', 'status', 'created_at')
                ->get()
                ->map(fn($d) => [
                    'type' => 'Deposit',
                    'amount' => $d->amount,
                    'status' => ucfirst($d->status),
                    'date' => $d->created_at->toIso8601String(),
                ]);

            // Fetch withdrawals
            $withdrawals = Withdrawal::where('user_id', $user->id)
                ->select('id', 'amount', 'status', 'created_at')
                ->get()
                ->map(fn($w) => [
                    'type' => 'Withdrawal',
                    'amount' => $w->amount,
                    'status' => ucfirst($w->status),
                    'date' => $w->created_at->toIso8601String(),
                ]);

            // Fetch land transactions (purchases & sales)
       $landTransactions = Transaction::where('user_id', $user->id)
        ->select('id', 'land_id','type', 'units', 'amount', 'status', 'created_at')
        ->with('land:id,title')
        ->get()
        ->map(function ($t) {
            return [
                'type' => ucfirst($t->type),    
                'land' => $t->land->title ?? 'Unknown Land',
                'amount' => abs($t->amount), 
                'units' => abs($t->units),
                'status' => ucfirst($t->status),
                'date' => $t->created_at->toIso8601String(),
            ];
        });

            // Combine all
            $transactions = collect()
                ->merge($deposits)
                ->merge($withdrawals)
                ->merge($landTransactions)
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