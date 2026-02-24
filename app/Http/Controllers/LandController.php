<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\LandImage;
use App\Models\LandPriceHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Events\LandPriceChanged;

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
            $query = Land::where('is_available', true)
                ->where(function($q) {
                    $q->whereNotNull('coordinates')
                      ->orWhereNotNull('lat');
                });
            
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
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'coordinates' => 'nullable|array',
            'coordinates.type' => 'required_with:coordinates|in:Point,Polygon',
            'coordinates.coordinates' => 'required_with:coordinates',
            'images' => 'nullable|array',
            'images.*' => 'image|max:2048',
        ]);

        // Normalize and validate coordinates
        if (isset($data['coordinates'])) {
            $data['coordinates'] = $this->normalizeCoordinates($data['coordinates']);
            $this->validateGeoJSONStructure($data['coordinates']);
            
            // Auto-populate lat/lng from coordinates if not provided
            if (!isset($data['lat']) || !isset($data['lng'])) {
                $center = $this->extractCenter($data['coordinates']);
                $data['lat'] = $center['lat'];
                $data['lng'] = $center['lng'];
            }
        }

        $land = null;
        DB::transaction(function () use ($data, &$land) {
            // Convert GeoJSON to PostGIS geometry
            $geometryWKT = null;
            if (isset($data['coordinates'])) {
                $geometryWKT = $this->geoJsonToWKT($data['coordinates']);
            }

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

            // Set PostGIS geometry using raw SQL
            if ($geometryWKT) {
                DB::statement(
                    "UPDATE lands SET coordinates = ST_GeomFromText(?, 4326) WHERE id = ?",
                    [$geometryWKT, $land->id]
                );
            }

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
            'description' => 'nullable|string',
            'is_available' => 'sometimes|boolean',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'coordinates' => 'nullable|array',
            'coordinates.type' => 'required_with:coordinates|in:Point,Polygon',
            'coordinates.coordinates' => 'required_with:coordinates',
        ]);

        // Normalize and validate coordinates if provided
        if (!empty($data['coordinates'])) {
            $data['coordinates'] = $this->normalizeCoordinates($data['coordinates']);
            $this->validateGeoJSONStructure($data['coordinates']);

            // Always update lat/lng from center for polygon
            $center = $this->extractCenter($data['coordinates']);
            $data['lat'] = $center['lat'];
            $data['lng'] = $center['lng'];

            // Update PostGIS geometry
            $geometryWKT = $this->geoJsonToWKT($data['coordinates']);
            DB::statement(
                "UPDATE lands SET coordinates = ST_GeomFromText(?, 4326) WHERE id = ?",
                [$geometryWKT, $land->id]
            );
        }
        // If user sends lat/lng without coordinates, create Point geometry
        else if (isset($data['lat'], $data['lng'])) {
            $geometryWKT = sprintf('POINT(%F %F)', $data['lng'], $data['lat']);
            DB::statement(
                "UPDATE lands SET coordinates = ST_GeomFromText(?, 4326) WHERE id = ?",
                [$geometryWKT, $land->id]
            );
        }

        // Update the model (this will update lat/lng if they were set from polygon center)
        $land->update(collect($data)->except('price_per_unit_kobo')->toArray());

        $this->handleImages($request, $land);
        $this->refreshLandCache($land);
        Cache::tags(['lands:list','maps','admin:lands'])->flush();

        return $this->success($this->getCachedLand($land->id), 'Land updated');
    }

    public function updatePrice(Request $request, Land $land)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'price_per_unit_kobo' => 'required|integer|min:1',
            'price_date' => 'required|date',
        ]);

        DB::transaction(function () use ($land, $data) {
            LandPriceHistory::create([
                'land_id' => $land->id,
                'price_per_unit_kobo' => $data['price_per_unit_kobo'],
                'price_date' => $data['price_date'],
            ]);

            event(new LandPriceChanged($land->id, $data['price_per_unit_kobo'], $data['price_date']));
        });

        $this->refreshLandCache($land);
        Cache::tags(['lands:list', 'maps', 'admin:lands'])->flush();

        return $this->success($this->getCachedLand($land->id), 'Price updated');  
    }

    /* ================= HELPERS ================= */

    private function authorizeAdmin()
    {
        abort_if(!auth()->user()?->is_admin, 403);
    }

    /**
     * Normalize coordinates to ensure numeric values (not strings)
     */
    private function normalizeCoordinates(array $coords): array
    {
        if (!isset($coords['type'])) {
            return $coords;
        }

        if ($coords['type'] === 'Point') {
            if (isset($coords['coordinates']) && is_array($coords['coordinates']) && count($coords['coordinates']) === 2) {
                $coords['coordinates'][0] = (float) $coords['coordinates'][0]; // lng
                $coords['coordinates'][1] = (float) $coords['coordinates'][1]; // lat
            }
        } elseif ($coords['type'] === 'Polygon') {
            if (isset($coords['coordinates']) && is_array($coords['coordinates'])) {
                foreach ($coords['coordinates'] as $ringIndex => &$ring) {
                    if (is_array($ring)) {
                        foreach ($ring as $pointIndex => &$point) {
                            if (is_array($point) && count($point) === 2) {
                                $point[0] = (float) $point[0]; // lng
                                $point[1] = (float) $point[1]; // lat
                            }
                        }
                    }
                }
            }
        }
        
        return $coords;
    }

    /**
     * Validate GeoJSON structure for Point or Polygon
     */
    private function validateGeoJSONStructure(array $coordinates)
    {
        $type = $coordinates['type'] ?? null;

        if ($type === 'Point') {
            $this->validatePointStructure($coordinates);
        } elseif ($type === 'Polygon') {
            $this->validatePolygonStructure($coordinates);
        } else {
            abort(422, 'Invalid GeoJSON: type must be "Point" or "Polygon"');
        }
    }

    /**
     * Validate Point structure
     */
    private function validatePointStructure(array $coordinates)
    {
        if (!isset($coordinates['coordinates']) || !is_array($coordinates['coordinates'])) {
            abort(422, 'Invalid GeoJSON Point: coordinates array is required');
        }

        if (count($coordinates['coordinates']) !== 2) {
            abort(422, 'Invalid GeoJSON Point: must be [lng, lat]');
        }

        [$lng, $lat] = $coordinates['coordinates'];

        if (!is_numeric($lng) || $lng < -180 || $lng > 180) {
            abort(422, 'Invalid GeoJSON Point: longitude must be between -180 and 180');
        }

        if (!is_numeric($lat) || $lat < -90 || $lat > 90) {
            abort(422, 'Invalid GeoJSON Point: latitude must be between -90 and 90');
        }
    }

    /**
     * Validate Polygon structure
     */
    private function validatePolygonStructure(array $coordinates)
    {
        if (!isset($coordinates['coordinates']) || !is_array($coordinates['coordinates'])) {
            abort(422, 'Invalid GeoJSON Polygon: coordinates array is required');
        }

        foreach ($coordinates['coordinates'] as $ringIndex => $ring) {
            if (!is_array($ring) || count($ring) < 4) {
                abort(422, "Invalid GeoJSON Polygon: Ring $ringIndex must have at least 4 points");
            }

            foreach ($ring as $pointIndex => $point) {
                if (!is_array($point) || count($point) !== 2) {
                    abort(422, "Invalid GeoJSON Polygon: Point $pointIndex in ring $ringIndex must be [lng, lat]");
                }

                [$lng, $lat] = $point;

                if (!is_numeric($lng) || $lng < -180 || $lng > 180) {
                    abort(422, "Invalid GeoJSON Polygon: Invalid longitude at point $pointIndex");
                }

                if (!is_numeric($lat) || $lat < -90 || $lat > 90) {
                    abort(422, "Invalid GeoJSON Polygon: Invalid latitude at point $pointIndex");
                }
            }

            $first = $ring[0];
            $last = $ring[count($ring) - 1];
            
            if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
                abort(422, "Invalid GeoJSON Polygon: Ring $ringIndex must be closed");
            }
        }
    }

    /**
     * Extract center point from GeoJSON
     */
    private function extractCenter(array $coords): array
    {
        if ($coords['type'] === 'Point') {
            return [
                'lat' => $coords['coordinates'][1],
                'lng' => $coords['coordinates'][0]
            ];
        }

        if ($coords['type'] === 'Polygon') {
            $points = $coords['coordinates'][0];
            $latSum = 0;
            $lngSum = 0;
            $count = count($points) - 1; // Exclude closing point

            for ($i = 0; $i < $count; $i++) {
                $lngSum += $points[$i][0];
                $latSum += $points[$i][1];
            }

            return [
                'lat' => $latSum / $count,
                'lng' => $lngSum / $count
            ];
        }

        return ['lat' => null, 'lng' => null];
    }

    /**
     * Convert GeoJSON to WKT (Well-Known Text) for PostGIS
     */
    private function geoJsonToWKT(array $geoJson): string
    {
        if ($geoJson['type'] === 'Point') {
            [$lng, $lat] = $geoJson['coordinates'];
            return "POINT($lng $lat)";
        }

        if ($geoJson['type'] === 'Polygon') {
            $rings = [];
            foreach ($geoJson['coordinates'] as $ring) {
                $points = array_map(fn($p) => "{$p[0]} {$p[1]}", $ring);
                $rings[] = '(' . implode(', ', $points) . ')';
            }
            return 'POLYGON(' . implode(', ', $rings) . ')';
        }

        return '';
    }

    private function handleImages(Request $request, Land $land)
    {
        if (!$request->hasFile('images')) return;
        foreach ($request->file('images') as $image) {
            $path = $image->store('land_images', 'public');
            $land->images()->create(['image_path' => $path]);
        }
    }

    private function refreshLandCache(Land $land)
    {
        Cache::tags(['lands:item'])->forget("land:{$land->id}:full");
        Cache::tags(['lands:item'])->forget("land:{$land->id}:map");
        $this->getCachedLand($land->id);
        $this->getCachedLand($land->id, true);
    }

    private function getCachedLand($id, $map = false)
    {
        $key = $map ? "land:$id:map" : "land:$id:full";

        return Cache::tags(['lands:item'])->remember($key, now()->addMinutes(10), function () use ($id, $map) {
            $land = Land::with(['images', 'latestPrice'])->find($id);
            if (!$land) return null;

            $polygon      = null;
            $point        = null;
            $geometryType = null;

            if ($land->coordinates) {
                $geoJson = DB::selectOne(
                    "SELECT ST_AsGeoJSON(coordinates) as geojson FROM lands WHERE id = ?",
                    [$land->id]
                );

                if ($geoJson && $geoJson->geojson) {
                    $coords = json_decode($geoJson->geojson, true);

                    if ($coords && isset($coords['type'])) {
                        $geometryType = $coords['type'];

                        if ($coords['type'] === 'Polygon' && isset($coords['coordinates'][0])) {
                            $polygon = array_map(
                                fn($p) => ['lat' => $p[1], 'lng' => $p[0]],
                                $coords['coordinates'][0]
                            );
                        } elseif ($coords['type'] === 'Point' && isset($coords['coordinates'])) {
                            $point = [
                                'lat' => $coords['coordinates'][1],
                                'lng' => $coords['coordinates'][0],
                            ];
                        }
                    }
                }
            }

            $payload = [
                'id'                    => $land->id,
                'title'                 => $land->title,
                'location'              => $land->location,
                'size'                  => $land->size,
                'is_available'          => $land->is_available,
                'price_per_unit_kobo'   => $land->current_price_per_unit_kobo,
                'total_units'           => $land->total_units,
                'available_units'       => $land->available_units,
                'units_sold'            => $land->units_sold,
                'sold_percentage'       => $land->sold_percentage,
                'lat'                   => $land->lat,
                'lng'                   => $land->lng,
                'geometry_type'         => $geometryType,
                'polygon'               => $polygon,
                'point'                 => $point,
                'has_polygon'           => !is_null($polygon),
                'has_point'             => !is_null($point),
            ];

            if ($map) {
                return $payload;
            }

            return $payload + [
                'description' => $land->description,
                'images'      => $land->images->map(fn($i) => [
                    'id'  => $i->id,
                    'url' => $i->image_url,
                ]),
            ];
        });
    }

    private function success($data = null, $message = 'OK')
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => $message]);
    }

    private function error($message, $code = 400)
    {
        return response()->json(['success' => false, 'message' => $message], $code);
    }
}