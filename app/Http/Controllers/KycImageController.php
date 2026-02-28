<?php

namespace App\Http\Controllers;

use App\Models\KycVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KycImageController extends Controller
{
    private const ALLOWED_TYPES = ['id_front', 'id_back', 'selfie'];

    public function show(Request $request, int $id, string $imageType)
    {
        if (! in_array($imageType, self::ALLOWED_TYPES, true)) {
            return response()->json(['error' => 'Invalid image type.'], 422);
        }

        $kyc = KycVerification::findOrFail($id);

        // Ownership check: user can only see their own KYC images.
        // Admins can see all (AdminMiddleware already gates the admin routes).
        $user = $request->user();
        if (! $user->is_admin && $kyc->user_id !== $user->id) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        // Column name maps image type to storage path
        $columnMap = [
            'id_front' => 'id_front_path',
            'id_back'  => 'id_back_path',
            'selfie'   => 'selfie_path',
        ];

        $path = $kyc->{$columnMap[$imageType]} ?? null;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'Image not found.'], 404);
        }

        $file     = Storage::disk('local')->get($path);
        $mimeType = Storage::disk('local')->mimeType($path);

        return response($file, 200, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
            'Cache-Control'       => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
