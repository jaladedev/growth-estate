<?php

namespace App\Http\Controllers;

use App\Models\KycVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

class KycImageController extends Controller
{
    // Allowed image types mapped to their storage subdirectory
    private const IMAGE_TYPES = [
        'id_front'   => 'ids',
        'id_back'    => 'ids',
        'selfie'     => 'selfies',
    ];

    /**
     * Stream a KYC image to the authenticated user who owns it (or an admin).
     *
     * Route: GET /api/kyc/{id}/image/{imageType}
     *
     * Rate limit: 30 requests per minute per user to prevent bulk enumeration
     * if a JWT is ever compromised.
     */
    public function show(Request $request, int $id, string $imageType)
    {
        // ── Per-user rate limit ──────────────────────────────────────────────
        $rateLimitKey = 'kyc-image:' . $request->user()->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 30)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'message'     => 'Too many requests. Please slow down.',
                'retry_after' => $retryAfter,
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 60); // 1-minute window

        // ── Validate image type ──────────────────────────────────────────────
        if (! array_key_exists($imageType, self::IMAGE_TYPES)) {
            return response()->json(['message' => 'Invalid image type.'], 400);
        }

        // ── Ownership check ──────────────────────────────────────────────────
        $kyc  = KycVerification::find($id);
        $user = $request->user();

        if (! $kyc) {
            return response()->json(['message' => 'KYC record not found.'], 404);
        }

        if ($kyc->user_id !== $user->id && ! $user->is_admin) {
            // Return 404 instead of 403 to avoid confirming the record exists
            return response()->json(['message' => 'KYC record not found.'], 404);
        }

        // ── Resolve the file path ────────────────────────────────────────────
        $subdir   = self::IMAGE_TYPES[$imageType];
        $filename = $kyc->{$imageType . '_path'} ?? null;

        if (! $filename) {
            return response()->json(['message' => 'Image not available.'], 404);
        }

        // Images are stored in the private disk (storage/app/private/kyc/...)
        $path = "kyc/{$subdir}/{$filename}";

        if (! Storage::disk('private')->exists($path)) {
            return response()->json(['message' => 'Image file not found.'], 404);
        }

        $mimeType = Storage::disk('private')->mimeType($path);
        $stream   = Storage::disk('private')->readStream($path);

        return response()->stream(
            function () use ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type'        => $mimeType,
                'Content-Disposition' => 'inline',
                // Prevent downstream caching of sensitive KYC images
                'Cache-Control'       => 'no-store, no-cache, must-revalidate',
                'Pragma'              => 'no-cache',
            ]
        );
    }
}