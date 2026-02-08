<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Land;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Models\Transaction;
use App\Models\LandPriceHistory;
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
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return response()->json(['error' => 'User not authenticated'], 401);
        }

        // Get from user_land table (current holdings)
        $userLand = DB::table('user_land')
            ->where('user_id', $user->id)
            ->where('land_id', $landId)
            ->first();

        return response()->json([
            'land_id' => $landId,
            'units_owned' => $userLand ? $userLand->units : 0,
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

        // Get user's current holdings from user_land table
        $userLands = $user->userLands()
            ->with('land')
            ->where('units', '>', 0)
            ->get();

        if ($userLands->isEmpty()) {
            return response()->json(['owned_lands' => []]);
        }

        // Get all land IDs
        $landIds = $userLands->pluck('land_id')->toArray();

        // Get current prices for all lands in one query
        $prices = LandPriceHistory::currentPricesForLands($landIds);

        $ownedLands = $userLands->map(function ($userLand) use ($prices) {
            $price = $prices->get($userLand->land_id);
            $pricePerUnit = $price ? $price->price_per_unit_kobo : 0;

            return [
                'land_id' => $userLand->land->id,
                'land_name' => $userLand->land->title,
                'units_owned' => $userLand->units,
                'price_per_unit_kobo' => $pricePerUnit,
                'price_per_unit_naira' => $pricePerUnit / 100,
                'current_value' => ($userLand->units * $pricePerUnit) / 100,
            ];
        });

        return response()->json(['owned_lands' => $ownedLands]);
    }
    
    public function setTransactionPin(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'pin' => 'required|digits:4',
        ]);

        if ($user->transaction_pin) {
            return response()->json(['error' => 'Transaction PIN is already set'], 400);
        }

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

        if (!password_verify($request->old_pin, $user->transaction_pin)) {
            return response()->json(['error' => 'Old PIN is incorrect'], 400);
        }

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
                Log::error('Paystack bank verification failed', [
                    'user_id' => $user->id,
                    'status' => $resolveResponse->status(),
                ]);

                return response()->json([
                    'error' => 'Failed to verify account number. Please try again later.',
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
            Log::error('Bank update error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'An unexpected error occurred while updating bank details.',
            ], 500);
        }
    }

    public function getBanks()
    {
        try {
            $banks = $this->getPaystackBanks();
            return response()->json(['data' => $banks]);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch banks', ['error' => $e->getMessage()]);
            
            return response()->json([
                'error' => 'Failed to fetch bank list.',
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
                Log::error('Paystack account verification failed', [
                    'status' => $response->status(),
                    'account_number' => $request->account_number,
                    'bank_code' => $request->bank_code,
                ]);

                return response()->json(['error' => 'Verification failed. Please try again later.'], 400);
            }

            return response()->json($response->json());
        } catch (\Exception $e) {
            Log::error('Error verifying account with Paystack', [
                'message' => $e->getMessage(),
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

            // Get lands owned from user_land table (current holdings)
            $landsOwned = DB::table('user_land')
                ->where('user_id', $user->id)
                ->where('units', '>', 0)
                ->distinct('land_id')
                ->count('land_id');

            // Get total units from user_land table (current holdings)
            $unitsOwned = DB::table('user_land')
                ->where('user_id', $user->id)
                ->sum('units');

            // Get total invested (net investment after sales)
            $investedData = DB::table('purchases')
                ->select(
                    DB::raw('SUM(total_amount_paid_kobo) as total_paid'),
                    DB::raw('SUM(total_amount_received_kobo) as total_received')
                )
                ->where('user_id', $user->id)
                ->whereIn('status', ['completed', 'partially_sold'])
                ->first();

            $totalInvestedKobo = ($investedData->total_paid ?? 0) - ($investedData->total_received ?? 0);

            // Get total withdrawn
            $totalWithdrawn = DB::table('withdrawals')
                ->where('user_id', $user->id)
                ->where('status', 'completed')
                ->sum('amount_kobo') / 100;

            // Get pending withdrawals count
            $pendingWithdrawals = DB::table('withdrawals')
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->count();

            // Get balance
            $balance = $user->balance_kobo / 100;

            return response()->json([
                'success' => true,
                'data' => [
                    'lands_owned' => $landsOwned,
                    'units_owned' => $unitsOwned,
                    'total_invested' => $totalInvestedKobo / 100,
                    'total_withdrawn' => $totalWithdrawn,
                    'pending_withdrawals' => $pendingWithdrawals,
                    'balance' => $balance,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('getUserStats error', [
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching stats',
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

            // Deposits
            $deposits = Deposit::where('user_id', $user->id)
                ->select('id', 'amount_kobo', 'transaction_fee', 'total_kobo', 'status', 'created_at')
                ->get()
                ->map(fn($d) => [
                    'type' => 'Deposit',
                    'amount' => $d->amount_kobo / 100,
                    'transaction_fee' => $d->transaction_fee / 100,
                    'total' => $d->total_kobo / 100,
                    'status' => ucfirst($d->status),
                    'date' => $d->created_at->toIso8601String(),
                ]);

            // Withdrawals
            $withdrawals = Withdrawal::where('user_id', $user->id)
                ->select('id', 'amount_kobo', 'status', 'created_at')
                ->get()
                ->map(fn($w) => [
                    'type' => 'Withdrawal',
                    'amount' => $w->amount_kobo / 100,
                    'status' => ucfirst($w->status),
                    'date' => $w->created_at->toIso8601String(),
                ]);

            // Land transactions
            $landTransactions = Transaction::where('user_id', $user->id)
                ->select('id', 'land_id','type', 'units', 'amount_kobo', 'status', 'created_at')
                ->with('land:id,title')
                ->get()
                ->map(function ($t) {
                    return [
                        'type' => ucfirst($t->type),
                        'land' => $t->land->title ?? 'Unknown Land',
                        'amount' => abs($t->amount_kobo) / 100,
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
            Log::error('getUserTransactions error', [
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching transactions',
            ], 500);
        }
    }

    public function getPortfolioSummary(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            // Get user's current holdings from user_land
            $userLands = DB::table('user_land')
                ->where('user_id', $user->id)
                ->where('units', '>', 0)
                ->get();

            if ($userLands->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_units' => 0,
                        'total_invested_kobo' => 0,
                        'total_invested_naira' => 0,
                        'current_portfolio_value_kobo' => 0,
                        'current_portfolio_value_naira' => 0,
                        'total_profit_loss_kobo' => 0,
                        'total_profit_loss_naira' => 0,
                        'profit_loss_percent' => 0,
                        'lands' => [],
                    ],
                ]);
            }

            // Get land IDs
            $landIds = $userLands->pluck('land_id')->toArray();

            // Get current prices for all lands
            $prices = LandPriceHistory::currentPricesForLands($landIds);

            // Calculate total invested (all-time)
            $investedData = DB::table('purchases')
                ->select(
                    DB::raw('SUM(total_amount_paid_kobo) as total_paid'),
                    DB::raw('SUM(total_amount_received_kobo) as total_received')
                )
                ->where('user_id', $user->id)
                ->whereIn('status', ['completed', 'partially_sold'])
                ->first();

            $totalInvested = ($investedData->total_paid ?? 0) - ($investedData->total_received ?? 0);

            // Calculate current portfolio value and breakdown by land
            $totalUnits = 0;
            $totalValue = 0;
            $landBreakdown = [];

            foreach ($userLands as $userLand) {
                $price = $prices->get($userLand->land_id);
                $pricePerUnit = $price ? $price->price_per_unit_kobo : 0;
                $landValue = $userLand->units * $pricePerUnit;

                $totalUnits += $userLand->units;
                $totalValue += $landValue;

                // Get land details
                $land = DB::table('lands')->find($userLand->land_id);

                $landBreakdown[] = [
                    'land_id' => $userLand->land_id,
                    'land_name' => $land->title ?? 'Unknown',
                    'units' => $userLand->units,
                    'price_per_unit_kobo' => $pricePerUnit,
                    'price_per_unit_naira' => $pricePerUnit / 100,
                    'total_value_kobo' => $landValue,
                    'total_value_naira' => $landValue / 100,
                ];
            }

            // Calculate profit/loss
            $profitLoss = $totalValue - $totalInvested;
            $profitLossPercent = $totalInvested > 0 
                ? round(($profitLoss / $totalInvested) * 100, 2) 
                : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'total_units' => $totalUnits,
                    'total_invested_kobo' => $totalInvested,
                    'total_invested_naira' => $totalInvested / 100,
                    'current_portfolio_value_kobo' => $totalValue,
                    'current_portfolio_value_naira' => $totalValue / 100,
                    'total_profit_loss_kobo' => $profitLoss,
                    'total_profit_loss_naira' => $profitLoss / 100,
                    'profit_loss_percent' => $profitLossPercent,
                    'lands' => $landBreakdown,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Portfolio summary error', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching portfolio summary',
            ], 500);
        }
    }
}