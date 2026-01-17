<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\LandImage;
use App\Models\Transaction;
use App\Models\UserLand;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LandController extends Controller
{
    public function index(Request $request)
    {
        $cacheKey = 'lands_index_' . md5(json_encode($request->all()));

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($request) {
            $query = Land::with('images')
                ->where('is_available', true);

            // Bounding box filter (map viewport)
            if ($request->filled(['north', 'south', 'east', 'west'])) {
                $query->whereBetween('lat', [$request->south, $request->north])
                      ->whereBetween('lng', [$request->west, $request->east]);
            }

            return $this->success(
                $query->get()->map(fn ($land) => $this->mapPayload($land))
            );
        });
    }

    /**
     * Lightweight map-only endpoint (markers)
     */
    public function mapIndex(Request $request)
    {
        $query = Land::query()
            ->where('is_available', true)
            ->whereNotNull('lat')
            ->whereNotNull('lng');

        if ($request->filled(['north', 'south', 'east', 'west'])) {
            $query->whereBetween('lat', [$request->south, $request->north])
                  ->whereBetween('lng', [$request->west, $request->east]);
        }

        return $this->success(
            $query->get()->map(fn ($land) => [
                'id' => $land->id,
                'title' => $land->title,
                'lat' => $land->lat,
                'lng' => $land->lng,
                'price_per_unit' => $land->price_per_unit,
                'available_units' => $land->available_units,
                'map_color' => $this->getMapColor($land),
            ])
        );
    }

    /**
     * Single land
     */
    public function show($id)
    {
        $land = Land::with('images')->find($id);

        if (! $land) {
            return $this->error('Land not found', 404);
        }

        return $this->success($this->mapPayload($land));
    }

    /* =====================================================
     | ADMIN ENDPOINTS
     |===================================================== */

    public function adminIndex()
    {
        $this->authorizeAdmin();

        return $this->success(
            Land::with('images')
                ->get()
                ->map(fn ($land) => $this->mapPayload($land))
        );
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'size' => 'required|numeric',
            'price_per_unit' => 'required|numeric|min:1',
            'total_units' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'images' => 'nullable|array',
            'images.*' => 'image|max:2048',
            'is_available' => 'sometimes|boolean',
        ]);

        $land = Land::create([
            ...$data,
            'available_units' => $data['total_units'],
            'is_available' => $data['is_available'] ?? true,
        ]);

        $this->handleImages($request, $land);

        Cache::flush();

        return $this->success(
            $this->mapPayload($land),
            'Land created successfully'
        );
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        $land = Land::with('images')->find($id);
        if (! $land) {
            return $this->error('Land not found', 404);
        }

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'location' => 'sometimes|string|max:255',
            'size' => 'sometimes|numeric',
            'price_per_unit' => 'sometimes|numeric|min:1',
            'total_units' => 'sometimes|integer|min:1',
            'description' => 'nullable|string',
            'is_available' => 'sometimes|boolean',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'images' => 'nullable|array',
            'images.*' => 'image|max:2048',
            'remove_images' => 'nullable|array',
        ]);

        if (isset($data['total_units'])) {
            $sold = $land->total_units - $land->available_units;

            if ($data['total_units'] < $sold) {
                return $this->error(
                    "Total units cannot be less than sold units ({$sold})",
                    422
                );
            }

            $data['available_units'] = $data['total_units'] - $sold;
        }

        $land->update(collect($data)->except(['images', 'remove_images'])->toArray());

        if ($request->filled('remove_images')) {
            $this->removeImages($request->remove_images);
        }

        $this->handleImages($request, $land);

        Cache::flush();

        return $this->success(
            $this->mapPayload($land),
            'Land updated'
        );
    }

     public function disable($id)
    {
        $this->authorizeAdmin();

        Land::whereId($id)->update(['is_available' => false]);

        return response()->json(['message' => 'Land disabled']);
    }

    public function enable($id)
    {
        $this->authorizeAdmin();

        Land::whereId($id)->update(['is_available' => true]);

        return response()->json(['message' => 'Land enabled']);
    }

    /* =====================================================
     | TRANSACTIONS
     |===================================================== */

    public function buy(Request $request, $id)
    {
        $data = $request->validate([
            'units' => 'required|integer|min:1'
        ]);

        $user = $request->user();

        DB::transaction(function () use ($data, $id, $user) {
            $land = Land::lockForUpdate()->findOrFail($id);

            if (! $land->is_available) {
                throw ValidationException::withMessages([
                    'land' => 'Land not available'
                ]);
            }

            if ($land->available_units < $data['units']) {
                throw ValidationException::withMessages([
                    'units' => 'Insufficient units available'
                ]);
            }

            $amountKobo = bcmul($data['units'], bcmul($land->price_per_unit, 100));

            if ($user->balance_kobo < $amountKobo) {
                throw ValidationException::withMessages([
                    'wallet' => 'Insufficient wallet balance'
                ]);
            }

            $user->update([
                'balance_kobo' => $user->balance_kobo - $amountKobo
            ]);

            $land->decrement('available_units', $data['units']);

            if ($land->available_units === 0) {
                $land->update(['is_available' => false]);
            }

            LedgerEntry::create([
                'uid' => $user->id,
                'type' => 'purchase',
                'amount_kobo' => $amountKobo,
                'balance_after' => $user->balance_kobo,
                'reference' => 'LAND-' . Str::uuid(),
            ]);

            UserLand::updateOrCreate(
                ['user_id' => $user->id, 'land_id' => $land->id],
                ['units' => DB::raw("units + {$data['units']}")]
            );

            Transaction::create([
                'user_id' => $user->id,
                'land_id' => $land->id,
                'units' => $data['units'],
                'type' => 'purchase',
                'status' => 'completed',
                'amount_kobo' => $amountKobo,
                'reference' => 'TX-' . Str::uuid(),
                'transaction_date' => now(),
            ]);
        });

        Cache::flush();

        return $this->success(null, 'Purchase successful');
    }

    /* =====================================================
     | HELPERS
     |===================================================== */

    private function authorizeAdmin()
    {
        abort_if(! auth()->user()?->is_admin, 403, 'Unauthorized');
    }

    private function handleImages(Request $request, Land $land)
    {
        if (! $request->hasFile('images')) return;

        foreach ($request->file('images') as $image) {
            $path = $image->store('land_images', 'public');
            $land->images()->create(['image_path' => $path]);
        }
    }

    private function removeImages(array $ids)
    {
        $images = LandImage::whereIn('id', $ids)->get();

        foreach ($images as $img) {
            Storage::disk('public')->delete($img->image_path);
            $img->delete();
        }
    }

    private function getMapColor(Land $land)
    {
        $soldRatio = ($land->total_units - $land->available_units) / max(1, $land->total_units);

        return match (true) {
            $soldRatio < 0.25 => 'green',
            $soldRatio < 0.50 => 'yellow',
            $soldRatio < 0.75 => 'orange',
            default => 'red',
        };
    }

    private function mapPayload(Land $land)
    {
        return [
            'id' => $land->id,
            'title' => $land->title,
            'location' => $land->location,
            'size' => $land->size,
            'description' => $land->description,
            'price_per_unit' => $land->price_per_unit,
            'total_units' => $land->total_units,
            'available_units' => $land->available_units,
            'units_sold' => $land->total_units - $land->available_units,
            'sold_percentage' => $land->total_units
                ? round((($land->total_units - $land->available_units) / $land->total_units) * 100, 2)
                : 0,
            'map_color' => $this->getMapColor($land),
            'lat' => $land->lat,
            'lng' => $land->lng,
            'is_available' => (bool) $land->is_available,
            'images' => $land->images->map(fn ($img) => [
                'id' => $img->id,
                'url' => Storage::url($img->image_path),
            ]),
        ];
    }

    private function success($data = null, $message = 'OK')
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    private function error($message, $code = 400)
    {
        return response()->json([
            'success' => false,
            'message' => $message
        ], $code);
    }
}
