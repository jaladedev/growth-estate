<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Referral;
use App\Models\ReferralReward;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReferralController extends Controller
{
    /**
     * Get user's referral dashboard
     */
    public function dashboard()
    {
        $user = auth()->user();

        $referrals = Referral::where('referrer_id', $user->id)
            ->with('referredUser:id,name,email,created_at')
            ->latest()
            ->get();

        $rewards = ReferralReward::where('user_id', $user->id)
            ->with('referral.referredUser:id,name')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'referral_code' => $user->referral_code,
                'referral_link' => url("/register?ref={$user->referral_code}"),
                'total_referrals' => $referrals->count(),
                'completed_referrals' => $referrals->where('status', 'completed')->count(),
                'pending_referrals' => $referrals->where('status', 'pending')->count(),
                'total_rewards' => $rewards->sum('amount_kobo'),
                'unclaimed_rewards' => $rewards->where('claimed', false)->sum('amount_kobo'),
                'referrals' => $referrals,
                'rewards' => $rewards,
            ]
        ]);
    }

    /**
     * Validate referral code
     */
    public function validateCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $referrer = User::where('referral_code', $request->code)->first();

        if (!$referrer) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid referral code'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $referrer->referral_code,
                'referrer_name' => $referrer->name,
            ]
        ]);
    }

    /**
     * Apply referral code during registration
     * This should be called in your registration process
     */
    public function applyReferral(User $newUser, string $referralCode)
    {
        $referrer = User::where('referral_code', $referralCode)->first();

        if (!$referrer) {
            return false;
        }

        // Update new user's referred_by
        $newUser->update(['referred_by' => $referrer->id]);

        // Create referral record
        $referral = Referral::create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $newUser->id,
            'status' => 'pending',
        ]);

        return $referral;
    }

    /**
     * Complete referral (called when referred user makes first purchase)
     */
    public function completeReferral(User $referredUser)
    {
        $referral = Referral::where('referred_user_id', $referredUser->id)
            ->where('status', 'pending')
            ->first();

        if (!$referral) {
            return null;
        }

        DB::transaction(function () use ($referral) {
            // Mark referral as completed
            $referral->markCompleted();

            // Create rewards for referrer
            $this->createReferrerReward($referral);

            // Create rewards for referred user
            $this->createReferredUserReward($referral);
        });

        return $referral;
    }

    /**
     * Create reward for referrer
     */
    private function createReferrerReward(Referral $referral)
    {
        // Example: Give referrer 5000 kobo (₦50) cashback
        ReferralReward::create([
            'referral_id' => $referral->id,
            'user_id' => $referral->referrer_id,
            'reward_type' => 'cashback',
            'amount_kobo' => 5000, // ₦50
            'claimed' => false,
        ]);
    }

    /**
     * Create reward for referred user
     */
    private function createReferredUserReward(Referral $referral)
    {
        // Example: Give referred user 10% discount on first purchase
        ReferralReward::create([
            'referral_id' => $referral->id,
            'user_id' => $referral->referred_user_id,
            'reward_type' => 'discount',
            'discount_percentage' => 10,
            'claimed' => false,
        ]);
    }

    /**
     * Claim a reward
     */
    public function claimReward($rewardId)
    {
        $reward = ReferralReward::where('id', $rewardId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if ($reward->claimed) {
            return response()->json([
                'success' => false,
                'message' => 'Reward already claimed'
            ], 400);
        }

        DB::transaction(function () use ($reward) {
            $reward->claim();

            // Process the reward based on type
            switch ($reward->reward_type) {
                case 'cashback':
                    // Add to user's wallet/balance
                    // TODO: Implement wallet credit
                    break;
                
                case 'bonus_units':
                    // Add bonus units to a land
                    // TODO: Implement bonus units logic
                    break;
                
                case 'discount':
                    // Discount is applied at checkout
                    // Mark as claimed for tracking
                    break;
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Reward claimed successfully',
            'data' => $reward->fresh()
        ]);
    }

    /**
     * Get available rewards for current user
     */
    public function availableRewards()
    {
        $rewards = ReferralReward::where('user_id', auth()->id())
            ->where('claimed', false)
            ->with('referral.referredUser:id,name')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rewards
        ]);
    }

    /**
     * Admin: Get all referrals
     */
    public function adminIndex(Request $request)
    {
        $this->authorizeAdmin();

        $query = Referral::with(['referrer:id,name,email', 'referredUser:id,name,email']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $referrals = $query->latest()->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $referrals
        ]);
    }

    /**
     * Admin: Get referral statistics
     */
    public function adminStats()
    {
        $this->authorizeAdmin();

        $stats = [
            'total_referrals' => Referral::count(),
            'completed_referrals' => Referral::completed()->count(),
            'pending_referrals' => Referral::pending()->count(),
            'total_rewards_issued' => ReferralReward::sum('amount_kobo'),
            'unclaimed_rewards' => ReferralReward::unclaimed()->sum('amount_kobo'),
            'top_referrers' => User::withCount('referrals')
                ->having('referrals_count', '>', 0)
                ->orderByDesc('referrals_count')
                ->take(10)
                ->get(['id', 'name', 'email', 'referral_code']),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    private function authorizeAdmin()
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }
}
