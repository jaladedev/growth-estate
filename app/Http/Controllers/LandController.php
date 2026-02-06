<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\LandImage;
use App\Models\Transaction;
use App\Models\UserLand;
use App\Models\LedgerEntry;
use App\Models\LandPriceHistory;
use App\Events\LandUnitsPurchased;
use App\Events\LandPriceChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LandController extends Controller
{
    /* ================= PUBLIC ================= */

    public function index(Request $request)
    {
        $filterKey = md5(json_encode($request->only(['north','south','east','west'])));
        $cacheKey = "lands:list:$filterKey";

        $landIds = Cache::tags(['lands:list'])->remember($cacheKey, now()->addMinutes(5), function () use ($request) {
            $query = Land::where('is_available', true);
            if ($request->filled(['north','south','east','west'])) {
                $query->withinBounds($request->north, $request->south, $request->east, $request->west);
            }
            return $query->pluck('id')->toArray();
        });

        $lands = collect($landIds)->map(fn($id) => $this->getCachedLand($id));
        return $this->success($lands);
    }

    public function mapIndex(Request $request)
    {
        $filterKey = md5(json_encode($request->only(['north','south','east','west'])));
        $cacheKey = "lands:map:$filterKey";

        $landIds = Cache::tags(['maps'])->remember($cacheKey, now()->addMinutes(5), function () use ($request) {
            $query = Land::where('is_available', true)->whereNotNull('coordinates');
            if ($request->filled(['north','south','east','west'])) {
                $query->withinBounds($request->north, $request->south, $request->east, $request->west);
            }
            return $query->pluck('id')->toArray();
        });

        $lands = collect($landIds)->map(fn($id) => $this->getCachedLand($id, true));
        return $this->success($lands);
    }

    public function show($id)
    {
        return $this->success($this->getCachedLand($id));
    }

    /* ================= ADMIN ================= */

    public function adminIndex()
    {
        $this->authorizeAdmin();

        $landIds = Cache::tags(['admin:lands'])->remember('admin:lands:index', now()->addMinutes(2), function () {
            return Land::pluck('id')->toArray();
        });

        $lands = collect($landIds)->map(fn($id) => $this->getCachedLand($id));
        return $this->success($lands);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'title' => 'required|string',
            'location' => 'required|string',
            'size' => 'required|numeric',
            'total_units' => 'required|integer|min:1',
            'price_per_unit_kobo' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'images' => 'nullable|array',
            'images.*' => 'image|max:2048',
        ]);

        DB::transaction(function () use ($data, &$land) {
            $land = Land::create([
                'title' => $data['title'],
                'location' => $data['location'],
                'size' => $data['size'],
                'total_units' => $data['total_units'],
                'available_units' => $data['total_units'],
                'description' => $data['description'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'is_available' => true,
            ]);

            LandPriceHistory::create([
                'land_id' => $land->id,
                'price_per_unit_kobo' => $data['price_per_unit_kobo'],
                'price_date' => now()->toDateString(),
            ]);
        });
        $this->handleImages($request, $land);
        $this->refreshLandCache($land);
        Cache::tags(['lands:list', 'maps', 'admin:lands'])->flush();

        return $this->success($this->getCachedLand($land->id), 'Land created');
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        $land = Land::findOrFail($id);

        $data = $request->validate([
            'title' => 'sometimes|string',
            'location' => 'sometimes|string',
            'size' => 'sometimes|numeric',
            'total_units' => 'sometimes|integer|min:1',
            'price_per_unit_kobo' => 'sometimes|integer|min:1',
            'description' => 'nullable|string',
            'is_available' => 'sometimes|boolean',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ]);

        if (isset($data['total_units'])) {
            $sold = $land->units_sold;
            abort_if($data['total_units'] < $sold, 422, 'Total units less than sold units');
            $data['available_units'] = $data['total_units'] - $sold;
        }

        if (isset($data['price_per_unit_kobo'])) {
            LandPriceHistory::create([
                'land_id' => $land->id,
                'price_per_unit_kobo' => $data['price_per_unit_kobo'],
                'price_date' => now()->toDateString(),
            ]);

            event(new LandPriceChanged($land->id, $data['price_per_unit_kobo'], now()->toDateString()));
        }

        $land->update(collect($data)->except('price_per_unit_kobo')->toArray());
        
        $this->handleImages($request, $land);
        $this->refreshLandCache($land);
        Cache::tags(['lands:list','maps','admin:lands'])->flush();

        return $this->success($this->getCachedLand($land->id), 'Land updated');
    }

    public function buy(Request $request, $id)
    {
        $request->validate(['units' => 'required|integer|min:1']);
        $user = $request->user();

        DB::transaction(function () use ($request, $user, $id, &$land) {
            // Lock the land row to prevent race conditions
            $land = Land::lockForUpdate()->findOrFail($id);

            if ($land->available_units < $request->units) {
                throw ValidationException::withMessages(['units' => 'Insufficient units available']);
            }

            $amount = $land->current_price_per_unit_kobo * $request->units;

            if ($user->balance_kobo < $amount) {
                throw ValidationException::withMessages(['wallet' => 'Insufficient balance']);
            }

            // Deduct user balance and decrease available units
            $user->decrement('balance_kobo', $amount);
            $land->decrement('available_units', $request->units);

            // Update or create user's land units
            UserLand::firstOrCreate(
                ['user_id' => $user->id, 'land_id' => $land->id],
                ['units' => 0]
            )->increment('units', $request->units);

            // Create transaction record
            Transaction::create([
                'user_id' => $user->id,
                'land_id' => $land->id,
                'units' => $request->units,
                'amount_kobo' => $amount,
                'status' => 'completed',
                'type' => 'purchase',
                'reference' => 'TX-' . Str::uuid(),
                'transaction_date' => now(),
            ]);

            // Ledger entry
            LedgerEntry::create([
                'uid' => $user->id,
                'type' => 'purchase',
                'amount_kobo' => $amount,
                'balance_after' => $user->balance_kobo,
                'reference' => 'LAND-' . Str::uuid(),
            ]);

            // Trigger event for listeners
            event(new LandUnitsPurchased($user->id, $land->id, $request->units, $land->current_price_per_unit_kobo, $amount));

            //  Refresh cache AFTER commit
            DB::afterCommit(function () use ($land) {
                // Refresh land instance
                $land->refresh();

                // Forget individual land caches
                Cache::tags(['lands:item'])->forget("land:{$land->id}:full");
                Cache::tags(['lands:item'])->forget("land:{$land->id}:map");

                // Flush list/map caches
                Cache::tags(['lands:list','maps','admin:lands'])->flush();

                // Optional: Preload fresh cache to avoid first-hit delay
                app()->call(fn() => (new self)->getCachedLand($land->id));
                app()->call(fn() => (new self)->getCachedLand($land->id, true));
            });
        });

        return $this->success(null, 'Purchase successful');
    }

    /* ================= HELPERS ================= */

    private function authorizeAdmin()
    {
        abort_if(!auth()->user()?->is_admin, 403);
    }

    private function getMapColor(Land $land)
    {
        $soldRatio = ($land->total_units - $land->available_units) / max(1, $land->total_units);
        return match(true) {
            $soldRatio < 0.25 => 'green',
            $soldRatio < 0.5 => 'yellow',
            $soldRatio < 0.75 => 'orange',
            default => 'red',
        };
    }

    private function handleImages(Request $request, Land $land)
    {
        if (!$request->hasFile('images')) return;
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

    private function refreshLandCache(Land $land)
    {
        // Forget existing caches
        Cache::tags(['lands:item'])->forget("land:{$land->id}:full");
        Cache::tags(['lands:item'])->forget("land:{$land->id}:map");

        // Rebuild immediately
        $this->getCachedLand($land->id);
        $this->getCachedLand($land->id, true);
    }

    private function getCachedLand($id, $map = false)
    {
        $key = $map ? "land:$id:map" : "land:$id:full";

        return Cache::tags(['lands:item'])->remember($key, now()->addMinutes(10), function () use ($id, $map) {
            $land = Land::with(['images','latestPrice'])->find($id);
            if (!$land) return null;

            $payload = [
                'id' => $land->id,
                'title' => $land->title,
                'location' => $land->location,
                'size' => $land->size,
                'is_available' => $land->is_available,
                'price_per_unit_kobo' => $land->current_price_per_unit_kobo,
                'total_units' => $land->total_units,
                'available_units' => $land->available_units,
                'units_sold' => $land->units_sold,
                'sold_percentage' => $land->sold_percentage,
                'map_color' => $land->map_color,
                'lat' => $land->lat,
                'lng' => $land->lng,
            ];

            return $map ? $payload : $payload + [
                'description' => $land->description,
                'coordinates' => $land->coordinates_geojson,
                'images' => $land->images->map(fn($i) => [
                    'id' => $i->id,
                    'url' => Storage::url($i->image_path),
                ]),
            ];
        });
    }

    private function mapPayload(Land $land)
    {
        return [
            'id' => $land->id,
            'title' => $land->title,
            'location' => $land->location,
            'size' => $land->size,
            'description' => $land->description,
            'price_per_unit_kobo' => $land->current_price_per_unit_kobo,
            'total_units' => $land->total_units,
            'available_units' => $land->available_units,
            'units_sold' => $land->total_units - $land->available_units,
            'sold_percentage' => $land->total_units
                ? round((($land->total_units - $land->available_units) / $land->total_units) * 100, 2)
                : 0,
            'heat' => $land->total_units
                ? min(1, round(log10(1 + ($land->total_units - $land->available_units)) / log10(1 + $land->total_units), 3))
                : 0,
            'map_color' => $this->getMapColor($land),
            'coordinates' => $land->coordinates_geojson,
            'lat' => $land->lat,
            'lng' => $land->lng,
            'is_available' => (bool)$land->is_available,
            'images' => $land->images->map(fn($img) => ['id' => $img->id, 'url' => Storage::url($img->image_path)]),
        ];
    }

    private function success($data = null, $message = 'OK')
    {
        return response()->json(compact('data', 'message') + ['success' => true]);
    }

    private function error($message, $code = 400)
    {
        return response()->json(['success' => false, 'message' => $message], $code);
    }
}
