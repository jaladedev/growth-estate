<?php

namespace App\Http\Controllers;

use App\Models\KycVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KycController extends Controller
{
    /**
     * Get current user's KYC status
     */
    public function status()
    {
        $kyc = auth()->user()->kycVerification;

        if (!$kyc) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'not_submitted',
                    'is_verified' => false,
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $kyc->status,
                'is_verified' => $kyc->status === 'approved',
                'submission_date' => $kyc->created_at,
                'verified_at' => $kyc->verified_at,
                'rejection_reason' => $kyc->rejection_reason,
            ]
        ]);
    }

    /**
     * Submit KYC verification
     */
    public function submit(Request $request)
    {
        $user = auth()->user();

        // Check if already verified
        if ($user->is_kyc_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Your KYC is already verified'
            ], 400);
        }

        // Check if pending
        $existingKyc = $user->kycVerification;
        if ($existingKyc && $existingKyc->status === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'You have a pending KYC verification'
            ], 400);
        }

        $data = $request->validate([
            'full_name' => 'required|string|max:255',
            'date_of_birth' => 'required|date|before:today',
            'phone_number' => 'required|string|max:20',
            'address' => 'required|string',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'country' => 'sometimes|string|max:100',
            'id_type' => 'required|in:nin,drivers_license,voters_card,passport,bvn',
            'id_number' => 'required|string|max:50',
            'id_front' => 'required|image|max:5120', // 5MB
            'id_back' => 'nullable|image|max:5120',
            'selfie' => 'required|image|max:5120',
        ]);

        // Upload images
        $idFrontPath = $request->file('id_front')->store('kyc/ids', 'public');
        $idBackPath = $request->hasFile('id_back') 
            ? $request->file('id_back')->store('kyc/ids', 'public') 
            : null;
        $selfiePath = $request->file('selfie')->store('kyc/selfies', 'public');

        // Create or update KYC
        $kyc = $user->kycVerification()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'full_name' => $data['full_name'],
                'date_of_birth' => $data['date_of_birth'],
                'phone_number' => $data['phone_number'],
                'address' => $data['address'],
                'city' => $data['city'],
                'state' => $data['state'],
                'country' => $data['country'] ?? 'Nigeria',
                'id_type' => $data['id_type'],
                'id_number' => $data['id_number'],
                'id_front_path' => $idFrontPath,
                'id_back_path' => $idBackPath,
                'selfie_path' => $selfiePath,
                'status' => 'pending',
                'rejection_reason' => null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'KYC submitted successfully. We will review it shortly.',
            'data' => [
                'status' => $kyc->status,
                'submitted_at' => $kyc->created_at,
            ]
        ]);
    }

    /**
     * Admin: List all KYC submissions
     */
    public function adminIndex(Request $request)
    {
        $this->authorizeAdmin();

        $status = $request->query('status');

        $query = KycVerification::with('user:id,name,email');

        if ($status && in_array($status, ['pending', 'approved', 'rejected'])) {
            $query->where('status', $status);
        }

        $kycs = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $kycs
        ]);
    }

    /**
     * Admin: View single KYC submission
     */
    public function adminShow($id)
    {
        $this->authorizeAdmin();

        $kyc = KycVerification::with('user:id,name,email')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $kyc
        ]);
    }

    /**
     * Admin: Approve KYC
     */
    public function approve($id)
    {
        $this->authorizeAdmin();

        $kyc = KycVerification::findOrFail($id);

        if ($kyc->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'KYC is already approved'
            ], 400);
        }

        $kyc->update([
            'status' => 'approved',
            'verified_at' => now(),
            'verified_by' => auth()->id(),
            'rejection_reason' => null,
        ]);

        // TODO: Send notification to user

        return response()->json([
            'success' => true,
            'message' => 'KYC approved successfully',
            'data' => $kyc
        ]);
    }

    /**
     * Admin: Reject KYC
     */
    public function reject(Request $request, $id)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'reason' => 'required|string'
        ]);

        $kyc = KycVerification::findOrFail($id);

        $kyc->update([
            'status' => 'rejected',
            'rejection_reason' => $data['reason'],
            'verified_at' => null,
            'verified_by' => auth()->id(),
        ]);

        // TODO: Send notification to user

        return response()->json([
            'success' => true,
            'message' => 'KYC rejected',
            'data' => $kyc
        ]);
    }

    /**
     * Admin: Request resubmission
     */
    public function requestResubmit(Request $request, $id)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'reason' => 'required|string'
        ]);

        $kyc = KycVerification::findOrFail($id);

        $kyc->update([
            'status' => 'resubmit',
            'rejection_reason' => $data['reason'],
            'verified_at' => null,
        ]);

        // TODO: Send notification to user

        return response()->json([
            'success' => true,
            'message' => 'Resubmission requested',
            'data' => $kyc
        ]);
    }

    private function authorizeAdmin()
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }
}
