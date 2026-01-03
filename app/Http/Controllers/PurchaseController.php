<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\Transaction;
use App\Models\Purchase;
use App\Notifications\PurchaseConfirmed;
use App\Notifications\SaleConfirmed;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PurchaseController extends Controller
{
    /**
     * PURCHASE LAND UNITS
     */
    public function purchase(Request $request, $landId)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'units' => 'required|integer|min:1',
        ]);

        try {
            return DB::transaction(function () use ($request, $landId, $user) {

                // Lock land row to prevent overselling
                $land = Land::lockForUpdate()->findOrFail($landId);

                if (! $land->is_available || $land->available_units < $request->units) {
                    return response()->json([
                        'message' => 'Not enough units available'
                    ], 422);
                }

                // Convert price to kobo
                $pricePerUnitKobo = $land->price_per_unit * 100;
                $totalCostKobo = $pricePerUnitKobo * $request->units;

                // Check wallet balance
                if ($user->balance_kobo < $totalCostKobo) {
                    return response()->json([
                        'message' => 'Insufficient balance'
                    ], 422);
                }

                $referenceCode = 'PUR-' . now()->format('Ymd-His') . '-' . Str::random(6);

                // Deduct wallet
                $user->decrement('balance_kobo', $totalCostKobo);

                // Deduct land units
                $land->decrement('available_units', $request->units);
                $land->is_available = $land->available_units > 0;
                $land->save();

                // Transaction record
                $transaction = Transaction::create([
                    'land_id'     => $land->id,
                    'user_id'     => $user->id,
                    'type'        => 'purchase',
                    'units'       => $request->units,
                    'amount_kobo' => $totalCostKobo,
                    'status'      => 'completed',
                    'reference'   => $referenceCode,
                    'message'     => 'Land units purchased successfully',
                ]);

                // Purchase summary
                $purchase = Purchase::firstOrCreate(
                    ['user_id' => $user->id, 'land_id' => $land->id],
                    [
                        'units' => 0,
                        'total_amount_paid_kobo' => 0,
                        'purchase_date' => now(),
                    ]
                );

                $purchase->increment('units', $request->units);
                $purchase->increment('total_amount_paid_kobo', $totalCostKobo);
                $purchase->purchase_date = now();
                $purchase->reference = $referenceCode;
                $purchase->save();

                // Notify user
                $user->notify(new PurchaseConfirmed($transaction));

                return response()->json([
                    'message' => 'Purchase successful',
                    'reference' => $referenceCode,
                    'amount_paid_kobo' => $totalCostKobo,
                ]);
            });

        } catch (\Throwable $e) {
            Log::error('Purchase failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'land_id' => $landId,
            ]);

            return response()->json([
                'message' => 'Transaction failed'
            ], 500);
        }
    }

    /**
     * SELL LAND UNITS
     */
    public function sellUnits(Request $request, $landId)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'units' => 'required|integer|min:1',
        ]);

        try {
            return DB::transaction(function () use ($request, $landId, $user) {

                $purchase = Purchase::where('user_id', $user->id)
                    ->where('land_id', $landId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($purchase->units < $request->units) {
                    return response()->json([
                        'message' => 'Not enough units to sell'
                    ], 422);
                }

                $land = Land::lockForUpdate()->findOrFail($landId);

                // Convert price to kobo
                $pricePerUnitKobo = $land->price_per_unit * 100;
                $totalReceivedKobo = $pricePerUnitKobo * $request->units;

                $referenceCode = 'SALE-' . now()->format('Ymd-His') . '-' . Str::random(6);

                // Update purchase record
                $purchase->decrement('units', $request->units);
                $purchase->increment('units_sold', $request->units);
                $purchase->increment('total_amount_received_kobo', $totalReceivedKobo);
                $purchase->sell_date = now();
                $purchase->save();

                // Restore land units
                $land->increment('available_units', $request->units);
                $land->is_available = true;
                $land->save();

                // Credit wallet
                $user->increment('balance_kobo', $totalReceivedKobo);

                // Transaction record
                $transaction = Transaction::create([
                    'land_id'     => $land->id,
                    'user_id'     => $user->id,
                    'type'        => 'sale',
                    'units'       => $request->units,
                    'amount_kobo' => $totalReceivedKobo,
                    'status'      => 'completed',
                    'reference'   => $referenceCode,
                    'message'     => 'Land units sold successfully',
                ]);

                // Notify user
                $user->notify(new SaleConfirmed($transaction));

                return response()->json([
                    'message' => 'Units sold successfully',
                    'reference' => $referenceCode,
                    'amount_received_kobo' => $totalReceivedKobo,
                ]);
            });

        } catch (\Throwable $e) {
            Log::error('Sale failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'land_id' => $landId,
            ]);

            return response()->json([
                'message' => 'Sale transaction failed'
            ], 500);
        }
    }
}
