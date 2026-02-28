<?php

namespace App\Http\Controllers;

use App\Events\LandUnitsPurchased;
use App\Events\LandUnitsSold;
use App\Models\Land;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    /**
     * PURCHASE LAND UNITS
     *
     * Payment priority:
     *   1. Rewards balance is applied first (up to the full cost).
     *   2. Remaining cost is taken from the main wallet balance.
     *
     * The `use_rewards` flag (default true) lets the frontend opt out if needed.
     */
    public function purchase(Request $request, $landId)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'units'       => ['required', 'integer', 'min:1'],
            'use_rewards' => ['sometimes', 'boolean'],
        ]);

        $useRewards = $request->boolean('use_rewards', true);

        try {
            return DB::transaction(function () use ($request, $landId, $user, $useRewards) {

                $land = Land::lockForUpdate()->findOrFail($landId);

                if (! $land->is_available || $land->available_units < $request->units) {
                    throw ValidationException::withMessages([
                        'units' => 'Insufficient units available',
                    ]);
                }

                $pricePerUnit = $land->current_price_per_unit_kobo;
                $totalCost    = $pricePerUnit * $request->units;

                // ── Rewards split ────────────────────────────────────────────
                $rewardsUsed = 0;
                $mainUsed    = $totalCost;

                if ($useRewards && $user->rewards_balance_kobo > 0) {
                    $rewardsUsed = min($user->rewards_balance_kobo, $totalCost);
                    $mainUsed    = $totalCost - $rewardsUsed;
                }
                // ─────────────────────────────────────────────────────────────

                // Check combined affordability
                if ($user->balance_kobo < $mainUsed) {
                    throw ValidationException::withMessages([
                        'wallet' => 'Insufficient wallet balance',
                    ]);
                }

                $reference = 'PUR-' . Str::uuid();

                // Debit rewards wallet first
                if ($rewardsUsed > 0) {
                    $user->spendRewards($rewardsUsed, $reference, "Purchase: {$land->title}");
                }

                // Debit main wallet for remainder
                if ($mainUsed > 0) {
                    $user->decrement('balance_kobo', $mainUsed);
                    $balanceAfter = $user->fresh()->balance_kobo;

                    LedgerEntry::create([
                        'uid'           => $user->id,
                        'type'          => 'withdrawal',
                        'amount_kobo'   => $mainUsed,
                        'balance_after' => $balanceAfter,
                        'reference'     => $reference,
                    ]);
                }

                // Update land availability
                $land->decrement('available_units', $request->units);
                $land->is_available = $land->available_units > 0;
                $land->save();

                $isFirstPurchase = ! Purchase::where('user_id', $user->id)->exists();

                // Purchase summary (upsert)
                $purchase = Purchase::lockForUpdate()->firstOrCreate(
                    ['user_id' => $user->id, 'land_id' => $land->id],
                    [
                        'units'                      => 0,
                        'units_sold'                 => 0,
                        'total_amount_paid_kobo'     => 0,
                        'total_amount_received_kobo' => 0,
                        'status'                     => 'active',
                        'purchase_date'              => now(),
                    ]
                );

                $purchase->increment('units', $request->units);
                $purchase->increment('total_amount_paid_kobo', $totalCost);
                $purchase->reference    = $reference;
                $purchase->purchase_date = now();
                $purchase->save();

                // Portfolio (source of truth)
                UserLand::firstOrCreate(
                    ['user_id' => $user->id, 'land_id' => $land->id],
                    ['units' => 0]
                )->increment('units', $request->units);

                // Transaction log
                Transaction::create([
                    'user_id'          => $user->id,
                    'land_id'          => $land->id,
                    'type'             => 'purchase',
                    'units'            => $request->units,
                    'amount_kobo'      => $totalCost,
                    'status'           => 'completed',
                    'reference'        => $reference,
                    'transaction_date' => now(),
                ]);

                if ($isFirstPurchase) {
                    $this->completeReferral($user);
                }

                event(new LandUnitsPurchased(
                    $user->id,
                    $land->id,
                    $request->units,
                    $pricePerUnit,
                    $totalCost
                ));

                return response()->json([
                    'message'              => 'Purchase successful',
                    'reference'            => $reference,
                    'total_cost_kobo'      => $totalCost,
                    'paid_from_rewards_kobo' => $rewardsUsed,
                    'paid_from_wallet_kobo'  => $mainUsed,
                    'remaining_units'      => $land->available_units,
                ]);
            });

        } catch (\Throwable $e) {
            Log::error('Purchase failed', [
                'error'   => $e->getMessage(),
                'user_id' => $user->id,
                'land_id' => $landId,
            ]);

            throw $e;
        }
    }

    /**
     * SELL LAND UNITS
     */
    public function sellUnits(Request $request, $landId)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'units' => ['required', 'integer', 'min:1'],
        ]);

        try {
            return DB::transaction(function () use ($request, $landId, $user) {

                $purchase = Purchase::where('user_id', $user->id)
                    ->where('land_id', $landId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($purchase->units < $request->units) {
                    throw ValidationException::withMessages([
                        'units' => 'Not enough units to sell',
                    ]);
                }

                $land = Land::lockForUpdate()->findOrFail($landId);

                $pricePerUnit  = $land->current_price_per_unit_kobo;
                $totalReceived = $pricePerUnit * $request->units;
                $reference     = 'SALE-' . Str::uuid();

                // Update purchase record
                $purchase->decrement('units', $request->units);
                $purchase->increment('units_sold', $request->units);
                $purchase->increment('total_amount_received_kobo', $totalReceived);
                $purchase->sell_date = now();
                $purchase->status    = $purchase->units === 0 ? 'sold_out' : 'partially_sold';
                $purchase->reference = $reference;
                $purchase->save();

                // Restore land units
                $land->increment('available_units', $request->units);
                $land->is_available = true;
                $land->save();

                // Credit main wallet (sale proceeds are never rewards)
                $user->increment('balance_kobo', $totalReceived);
                $balanceAfter = $user->fresh()->balance_kobo;

                LedgerEntry::create([
                    'uid'           => $user->id,
                    'type'          => 'deposit',
                    'amount_kobo'   => $totalReceived,
                    'balance_after' => $balanceAfter,
                    'reference'     => $reference,
                ]);

                // Portfolio
                UserLand::where('user_id', $user->id)
                    ->where('land_id', $land->id)
                    ->decrement('units', $request->units);

                // Transaction log
                Transaction::create([
                    'user_id'          => $user->id,
                    'land_id'          => $land->id,
                    'type'             => 'sale',
                    'units'            => $request->units,
                    'amount_kobo'      => $totalReceived,
                    'status'           => 'completed',
                    'reference'        => $reference,
                    'transaction_date' => now(),
                ]);

                event(new LandUnitsSold(
                    $user->id,
                    $land->id,
                    $request->units,
                    $pricePerUnit,
                    $totalReceived
                ));

                return response()->json([
                    'message'                => 'Units sold successfully',
                    'reference'              => $reference,
                    'amount_received_kobo'   => $totalReceived,
                    'available_units'        => $land->available_units,
                ]);
            });

        } catch (\Throwable $e) {
            Log::error('Sale failed', [
                'error'   => $e->getMessage(),
                'user_id' => $user->id,
                'land_id' => $landId,
            ]);

            throw $e;
        }
    }

    private function completeReferral($referredUser): void
    {
        $referral = Referral::where('referred_user_id', $referredUser->id)
            ->where('status', 'pending')
            ->first();

        if (! $referral) {
            return;
        }

        try {
            DB::transaction(function () use ($referral) {
                $referral->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                ]);

                // Referrer gets cashback to rewards wallet
                ReferralReward::create([
                    'referral_id' => $referral->id,
                    'user_id'     => $referral->referrer_id,
                    'reward_type' => 'cashback',
                    'amount_kobo' => 5000, // ₦50
                    'claimed'     => false,
                ]);

                // Referred user gets discount reward
                ReferralReward::create([
                    'referral_id'         => $referral->id,
                    'user_id'             => $referral->referred_user_id,
                    'reward_type'         => 'discount',
                    'discount_percentage' => 10,
                    'claimed'             => false,
                ]);

                Log::info('Referral completed', [
                    'referral_id'      => $referral->id,
                    'referrer_id'      => $referral->referrer_id,
                    'referred_user_id' => $referral->referred_user_id,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to complete referral', [
                'referral_id' => $referral->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}