<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\Transaction;
use App\Models\Purchase;
use App\Models\User;
use App\Notifications\PurchaseConfirmed;
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
        $request->validate(['units' => 'required|numeric|min:1']);
        $land = Land::findOrFail($landId);

        if (!$land->is_available || $land->available_units < $request->units) {
            return response()->json(['error' => 'Not enough units available'], 400);
        }

        $totalPrice = $request->units * $land->price_per_unit;
        if ($user->balance < $totalPrice) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        try {
            // Generate a unique reference code for the transaction
            $referenceCode = 'PUR-' . now()->format('Ymd-His') . '-' . Str::random(6);

            DB::transaction(function () use ($user, $land, $request, $totalPrice, $referenceCode) {
                // Create a transaction record for the purchase
                $transaction = Transaction::create([
                    'land_id' => $land->id,
                    'user_id' => $user->id,
                    'units' => $request->units,
                    'price' => $totalPrice,
                    'status' => 'completed',
                    'reference' => $referenceCode, // Store the reference code
                ]);

                // Decrease the user's balance by the total price
                $user->decrement('balance', $totalPrice);

                // Decrease the available units of the land
                $land->decrement('available_units', $request->units);
                $land->is_available = $land->available_units > 0; // Update availability
                $land->save();

                // Update or create the purchase record for the user
                Purchase::updateOrCreate(
                    ['user_id' => $user->id, 'land_id' => $land->id],
                    [
                        'units' => DB::raw("units + {$transaction->units}"),
                        'total_amount_paid' => DB::raw("total_amount_paid + {$transaction->price}"),
                        'purchase_date' => now(),
                        'reference' => $referenceCode,
                    ]
                );

                // Notify the user of the successful purchase
             DB::table('notifications')->insert([
                'user_id' => $user->id,
                'type' => 'purchase',
                'title' => 'Purchase Confirmed',
                'message' => "Your purchase of {$request->units} units has been confirmed.",
                'is_read' => false,
                'created_at' => now(),
            ]);
            });

            return response()->json(['message' => 'Purchase successful', 'reference' => $referenceCode, 'amount_paid' => $totalPrice]);
        } catch (\Exception $e) {
            Log::error('Transaction failed', ['error' => $e->getMessage(), 'referenceCode' => $referenceCode]);
            return response()->json(['error' => 'Transaction failed'], 500);
        }
    }

    // Sell units
    public function sellUnits(Request $request, $landId)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $request->validate(['units' => 'required|numeric|min:1']);
        
        // Fetch the user's purchase record for the land
        $purchase = Purchase::where('user_id', $user->id)
            ->where('land_id', $landId)
            ->firstOrFail();
        
        // Check if the user has enough units to sell
        if ($purchase->units < $request->units) {
            return response()->json(['error' => 'Not enough units to sell'], 400);
        }

        // Fetch the land details
        $land = Land::findOrFail($landId);

        // Calculate the total amount received for selling the units
        $totalAmountReceived = $request->units * $land->price_per_unit;

        // Generate a unique reference code for the transaction
        $referenceCode = 'SALE-' . now()->format('Ymd-His') . '-' . Str::random(6);

        // Perform atomic updates
        DB::transaction(function () use ($user, $land, $purchase, $request, $totalAmountReceived, $referenceCode) {
            // Decrease purchased units
            $purchase->decrement('units', $request->units);

            // Update purchase financials
            $purchase->total_amount_paid -= $request->units * $land->price_per_unit;

            // Update sold tracking for this purchase
            $purchase->increment('units_sold', $request->units);
            $purchase->increment('total_amount_received', $totalAmountReceived);
            $purchase->sell_date = now();

            $purchase->save();

            // Update land available units
            $land->increment('available_units', $request->units);

            // Increase user's wallet balance
            $user->increment('balance', $totalAmountReceived);

            // Log transaction
            Transaction::create([
                'land_id' => $land->id,
                'user_id' => $user->id,
                'units' => -$request->units, // Negative because it's a sale
                'price' => -$totalAmountReceived, // Negative because it's a sale
                'status' => 'completed',
                'reference' => $referenceCode,
            ]);
        });

        // Create notification
        DB::table('notifications')->insert([
            'user_id' => $user->id,
            'type' => 'Sale',
            'title' => 'Sale Confirmed',
            'message' => "Your sale of {$request->units} units has been confirmed.",
            'is_read' => false,
            'created_at' => now(),
        ]);

        // Return response
        return response()->json([
            'message' => 'Units sold successfully',
            'reference' => $referenceCode,
            'amount_received' => $totalAmountReceived,
        ]);
    }


}