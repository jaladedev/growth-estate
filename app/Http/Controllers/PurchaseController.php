<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\Transaction;
use App\Models\Purchase;
use App\Models\User;
use App\Notifications\PurchaseConfirmed;
use App\Notifications\SaleConfirmed;
use App\Notifications\WithdrawalConfirmed;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class PurchaseController extends Controller
{
    // Purchase units of land
    public function purchase(Request $request, $landId)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'units' => 'required|numeric|min:1',
        ]);

        $land = Land::findOrFail($landId);

        // Check land availability
        if (!$land->is_available || $land->available_units < $request->units) {
            return response()->json(['error' => 'Not enough units available'], 400);
        }

        $totalPrice = $request->units * $land->price_per_unit;

        // Check user balance
        if ($user->balance < $totalPrice) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        try {
            $referenceCode = 'PUR-' . now()->format('Ymd-His') . '-' . Str::random(6);

            DB::transaction(function () use ($user, $land, $request, $totalPrice, $referenceCode) {
                // Create transaction record
                $transaction = Transaction::create([
                    'land_id'   => $land->id,
                    'user_id'   => $user->id,
                    'units'     => $request->units,
                    'price'     => $totalPrice,
                    'status'    => 'completed',
                    'reference' => $referenceCode,
                ]);

                // Update user's balance
                $user->decrement('balance', $totalPrice);

                // Update land availability
                $land->decrement('available_units', $request->units);
                $land->is_available = $land->available_units > 0;
                $land->save();

                // Create or update purchase record safely
                $purchase = Purchase::firstOrCreate(
                    ['user_id' => $user->id, 'land_id' => $land->id],
                    [
                        'units' => 0,
                        'total_amount_paid' => 0,
                        'purchase_date' => now(),
                        'reference' => $referenceCode,
                    ]
                );

                // Increment values safely (no DB::raw needed)
                $purchase->increment('units', $transaction->units);
                $purchase->increment('total_amount_paid', $transaction->price);
                $purchase->purchase_date = now();
                $purchase->reference = $referenceCode;
                $purchase->save();

                // Send notification to the user
                $user->notify(new PurchaseConfirmed($transaction));
            });

            return response()->json([
                'message' => 'Purchase successful',
                'reference' => $referenceCode,
                'amount_paid' => $totalPrice,
            ]);
        } catch (\Exception $e) {
            Log::error('Transaction failed', [
                'error' => $e->getMessage(),
                'referenceCode' => $referenceCode ?? null,
            ]);

            return response()->json(['error' => 'Transaction failed'], 500);
        }
    }

    // Sell units
  public function sellUnits(Request $request, $landId)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'units' => 'required|numeric|min:1',
        ]);

        // Fetch the user's purchase record for this land
        $purchase = Purchase::where('user_id', $user->id)
            ->where('land_id', $landId)
            ->firstOrFail();

        if ($purchase->units < $request->units) {
            return response()->json(['error' => 'Not enough units to sell'], 400);
        }

        $land = Land::findOrFail($landId);

        // Calculate sale details
        $totalAmountReceived = $request->units * $land->price_per_unit;
        $referenceCode = 'SALE-' . now()->format('Ymd-His') . '-' . Str::random(6);

        try {
            DB::transaction(function () use ($user, $land, $purchase, $request, $totalAmountReceived, $referenceCode) {
                // Update the purchase record
                $purchase->decrement('units', $request->units);
                $purchase->increment('units_sold', $request->units);
                $purchase->increment('total_amount_received', $totalAmountReceived);
                $purchase->sell_date = now(); 
                $purchase->save();

                // Update land and user wallet
                $land->increment('available_units', $request->units);
                $land->is_available = true;
                $land->save();

                $user->increment('balance', $totalAmountReceived);

                // Create transaction record
                $transaction = Transaction::create([
                    'land_id'   => $land->id,
                    'user_id'   => $user->id,
                    'units'     => -$request->units, // negative for sold units
                    'price'     => -$totalAmountReceived,
                    'status'    => 'completed',
                    'reference' => $referenceCode,
                ]);

                // Notify user 
                $user->notify(new SaleConfirmed($transaction));
            });

            return response()->json([
                'message' => 'Units sold successfully',
                'reference' => $referenceCode,
                'amount_received' => $totalAmountReceived,
            ]);

        } catch (\Exception $e) {
            Log::error('Sale transaction failed', [
                'error' => $e->getMessage(),
                'referenceCode' => $referenceCode ?? null,
            ]);

            return response()->json(['error' => 'Sale transaction failed'], 500);
        }
    }


}