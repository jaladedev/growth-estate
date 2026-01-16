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
use Illuminate\Support\Str;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Illuminate\Validation\ValidationException;

class LandController extends Controller
{
    public function index()
    {
        $lands = Land::with('images')
            ->where('is_available', true)
            ->get()
            ->map(fn ($land) => $this->mapPayload($land));

        return response()->json($lands);
    }

    public function show($id)
    {
        $land = Land::with('images')->find($id);

        if (! $land) {
            return response()->json(['message' => 'Land not found'], 404);
        }

        return response()->json($this->mapPayload($land));
    }

    public function adminIndex()
    {
        return response()->json(
            Land::with('images')->get()->map(fn ($land) => $this->mapPayload($land))
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
            'images.*' => 'image|mimes:jpg,jpeg,png|max:2048',
            'is_available' => 'sometimes|boolean',
        ]);

        $land = Land::create([
            'title' => $data['title'],
            'location' => $data['location'],
            'size' => $data['size'],
            'price_per_unit' => $data['price_per_unit'],
            'total_units' => $data['total_units'],
            'available_units' => $data['total_units'],
            'description' => $data['description'] ?? null,
            'is_available' => $data['is_available'] ?? true,

            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,

            // spatial column
            // 'coordinates' => isset($data['lat'], $data['lng'])
            //     ? new Point($data['lat'], $data['lng'])
            //     : null,
        ]);

        $this->handleImages($request, $land);

        return response()->json([
            'message' => 'Land created successfully',
            'land' => $this->mapPayload($land),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        $land = Land::with('images')->find($id);

        if (! $land) {
            return response()->json(['message' => 'Land not found'], 404);
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
            'images.*' => 'image|mimes:jpg,jpeg,png|max:2048',
            'remove_images' => 'nullable|array',
        ]);

        if (isset($data['total_units'])) {
            $sold = $land->total_units - $land->available_units;

            if ($data['total_units'] < $sold) {
                return response()->json([
                    'message' => "Total units cannot be less than sold units ({$sold})"
                ], 422);
            }

            $data['available_units'] = $data['total_units'] - $sold;
        }

        $updateData = collect($data)->except(['images', 'remove_images'])->toArray();

        // keep lat/lng + spatial in sync
        if (array_key_exists('lat', $data) && array_key_exists('lng', $data)) {
            $updateData['lat'] = $data['lat'];
            $updateData['lng'] = $data['lng'];

            // $updateData['coordinates'] =
            //     ($data['lat'] !== null && $data['lng'] !== null)
            //         ? new Point($data['lat'], $data['lng'])
            //         : null;
        }

        $land->update($updateData);

        if ($request->filled('remove_images')) {
            $this->removeImages($request->remove_images);
        }

        $this->handleImages($request, $land);

        return response()->json([
            'message' => 'Land updated',
            'land' => $this->mapPayload($land),
        ]);
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

    public function buy(Request $request, $id)
    {
        $request->validate(['units' => 'required|integer|min:1']);
        $user = $request->user();

        DB::transaction(function () use ($request, $id, $user) {

            $land = Land::lockForUpdate()->find($id);

            if (! $land || ! $land->is_available) {
                throw ValidationException::withMessages([
                    'land' => 'Land not available'
                ]);
            }

            if ($land->available_units < $request->units) {
                throw ValidationException::withMessages([
                    'units' => 'Insufficient units available'
                ]);
            }

            $amountKobo = $request->units * ($land->price_per_unit * 100);

            if ($user->balance_kobo < $amountKobo) {
                throw ValidationException::withMessages([
                    'wallet' => 'Insufficient wallet balance'
                ]);
            }

            $user->decrement('balance_kobo', $amountKobo);

            LedgerEntry::create([
                'uid' => $user->id,
                'type' => 'purchase',
                'amount_kobo' => $amountKobo,
                'balance_after' => $user->balance_kobo,
                'reference' => 'LAND-' . Str::uuid(),
            ]);

            $land->decrement('available_units', $request->units);

            if ($land->available_units === 0) {
                $land->update(['is_available' => false]);
            }

            UserLand::updateOrCreate(
                ['user_id' => $user->id, 'land_id' => $land->id],
                ['units' => DB::raw("units + {$request->units}")]
            );

            Transaction::create([
                'user_id' => $user->id,
                'land_id' => $land->id,
                'units' => $request->units,
                'status' => 'completed',
                'type' => 'purchase',
                'amount_kobo' => $amountKobo,
                'reference' => 'TX-' . Str::uuid(),
                'transaction_date' => now(),
            ]);
        });

        return response()->json(['message' => 'Purchase successful']);
    }

    /* 
    |==========================================================
    | HELPERS
    |==========================================================
    */

    private function authorizeAdmin()
    {
        if (! auth()->user()?->is_admin) {
            abort(403, 'Unauthorized');
        }
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
            'is_available' => (bool) $land->is_available,
            'available_units' => $land->available_units,
            'units_sold' => $land->total_units - $land->available_units,
            'sold_percentage' => $land->total_units
                ? round((($land->total_units - $land->available_units) / $land->total_units) * 100, 2)
                : 0,
            'map_color' => match (true) {
                ($land->total_units - $land->available_units) / max(1, $land->total_units) < 0.25 => 'green',
                ($land->total_units - $land->available_units) / max(1, $land->total_units) < 0.50 => 'yellow',
                ($land->total_units - $land->available_units) / max(1, $land->total_units) < 0.75 => 'orange',
                default => 'red',
            },

            'lat' => $land->lat,
            'lng' => $land->lng,

            // // spatial
            // 'coordinates' => $land->coordinates
            //     ? [
            //         'lat' => $land->coordinates->getLat(),
            //         'lng' => $land->coordinates->getLng(),
            //     ]
            //     : null,

            'images' => $land->images->map(fn ($img) => [
                'id' => $img->id,
                'url' => Storage::url($img->image_path),
            ]),
        ];
    }
}
