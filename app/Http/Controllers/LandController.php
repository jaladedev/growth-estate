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
                $query->withinBounds($request->north,$request->south,$request->east,$request->west);
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
                $query->withinBounds($request->north,$request->south,$request->east,$request->west);
            }

            return $query->pluck('id')->toArray();
        });

        $lands = collect($landIds)->map(fn($id) => $this->getCachedLand($id, true));

        return $this->success($lands);
    }

    public function show($id)
    {
        $land = $this->getCachedLand($id);
        if (! $land) return $this->error('Land not found', 404);
        return $this->success($land);
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
            'title'=>'required|string|max:255',
            'location'=>'required|string|max:255',
            'size'=>'required|numeric',
            'price_per_unit_kobo'=>'required|integer|min:1',
            'total_units'=>'required|integer|min:1',
            'description'=>'nullable|string',
            'lat'=>'nullable|numeric',
            'lng'=>'nullable|numeric',
            'images'=>'nullable|array',
            'images.*'=>'image|max:2048',
        ]);

        DB::transaction(function () use ($data, &$land) {
            $land = Land::create([
                ...$data,
                'available_units' => $data['total_units'],
                'is_available' => true,
            ]);

            LandPriceHistory::create([
                'land_id'=>$land->id,
                'price_per_unit_kobo'=>$data['price_per_unit_kobo'],
                'price_date'=>now()->toDateString(),
            ]);
        });

        $this->handleImages($request, $land);
        $this->cacheLand($land);

        Cache::tags(['lands:list','maps','admin:lands'])->flush();

        return $this->success($this->getCachedLand($land->id), 'Land created');
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        $land = Land::with('images')->find($id);
        if (! $land) return $this->error('Land not found', 404);

        $data = $request->validate([
            'title'=>'sometimes|string|max:255',
            'location'=>'sometimes|string|max:255',
            'size'=>'sometimes|numeric',
            'price_per_unit_kobo'=>'sometimes|numeric|min:1',
            'total_units'=>'sometimes|integer|min:1',
            'description'=>'nullable|string',
            'is_available'=>'sometimes|boolean',
            'lat'=>'nullable|numeric|between:-90,90',
            'lng'=>'nullable|numeric|between:-180,180',
            'images'=>'nullable|array',
            'images.*'=>'image|max:2048',
            'remove_images'=>'nullable|array',
        ]);

        if (isset($data['total_units'])) {
            $sold = $land->total_units - $land->available_units;
            if ($data['total_units'] < $sold) {
                return $this->error("Total units cannot be less than sold units ($sold)",422);
            }
            $data['available_units'] = $data['total_units'] - $sold;
        }

        $land->update(collect($data)->except(['images','remove_images'])->toArray());

        if ($request->filled('remove_images')) $this->removeImages($request->remove_images);
        $this->handleImages($request, $land);

        $this->cacheLand($land);

        Cache::tags(['lands:list','maps','admin:lands'])->flush();

        return $this->success($this->getCachedLand($land->id), 'Land updated');
    }

    public function buy(Request $request, $id)
    {
        $request->validate(['units'=>'required|integer|min:1']);
        $user = $request->user();

        DB::transaction(function () use ($request,$user,$id) {
            $land = Land::lockForUpdate()->findOrFail($id);

            if ($land->available_units < $request->units) {
                throw ValidationException::withMessages(['units'=>'Insufficient units available']);
            }

            $amount = $land->current_price_per_unit_kobo * $request->units;
            if ($user->balance_kobo < $amount) {
                throw ValidationException::withMessages(['wallet'=>'Insufficient balance']);
            }

            $user->decrement('balance_kobo', $amount);
            $land->decrement('available_units', $request->units);

            UserLand::firstOrCreate(
                ['user_id'=>$user->id,'land_id'=>$land->id],
                ['units'=>0]
            )->increment('units', $request->units);

            Transaction::create([
                'user_id'=>$user->id,
                'land_id'=>$land->id,
                'units'=>$request->units,
                'amount_kobo'=>$amount,
                'status'=>'completed',
                'type'=>'purchase',
                'reference'=>'TX-'.Str::uuid(),
                'transaction_date'=>now(),
            ]);

            LedgerEntry::create([
                'uid'=>$user->id,
                'type'=>'purchase',
                'amount_kobo'=>$amount,
                'balance_after'=>$user->balance_kobo,
                'reference'=>'LAND-'.Str::uuid(),
            ]);

            event(new LandUnitsPurchased($user->id,$land->id,$request->units,$land->current_price_per_unit_kobo,$amount));
            $this->cacheLand($land);
        });

        Cache::tags(['lands:list','maps','admin:lands'])->flush();

        return $this->success(null,'Purchase successful');
    }

    public function updatePrice(Request $request, Land $land)
    {
        $this->authorizeAdmin();

        $request->validate([
            'price_per_unit_kobo'=>'required|integer|min:1',
            'price_date'=>'required|date',
        ]);

        LandPriceHistory::create([
            'land_id'=>$land->id,
            'price_per_unit_kobo'=>$request->price_per_unit_kobo,
            'price_date'=>$request->price_date,
        ]);

        event(new LandPriceChanged($land->id,$request->price_per_unit_kobo,$request->price_date));

        $land->refresh();
        $this->cacheLand($land);

        Cache::tags(['lands:list','maps','admin:lands'])->flush();

        return $this->success(null,'Price updated');
    }

    /* ================= HELPERS ================= */

    private function authorizeAdmin()
    {
        abort_if(! auth()->user()?->is_admin,403);
    }

    private function getMapColor(Land $land)
    {
        $soldRatio = ($land->total_units - $land->available_units)/max(1,$land->total_units);
        return match(true){
            $soldRatio<0.25=>'green',
            $soldRatio<0.5=>'yellow',
            $soldRatio<0.75=>'orange',
            default=>'red',
        };
    }

    private function handleImages(Request $request, Land $land)
    {
        if (!$request->hasFile('images')) return;
        foreach($request->file('images') as $image){
            $path = $image->store('land_images','public');
            $land->images()->create(['image_path'=>$path]);
        }
    }

    private function removeImages(array $ids)
    {
        $images = LandImage::whereIn('id',$ids)->get();
        foreach($images as $img){
            Storage::disk('public')->delete($img->image_path);
            $img->delete();
        }
    }

    private function cacheLand(Land $land)
    {
        Cache::tags(['lands:item'])->put("land:{$land->id}", $this->mapPayload($land), now()->addMinutes(10));
    }

    private function getCachedLand($id, $map=false)
    {
        return Cache::tags(['lands:item'])->remember("land:$id", now()->addMinutes(10), function () use ($id, $map){
            $land = Land::with('images')->find($id);
            if (! $land) return null;

            $payload = $this->mapPayload($land);

            // For mapIndex, simplify payload
            if ($map){
                return [
                    'id'=>$payload['id'],
                    'title'=>$payload['title'],
                    'lat'=>$payload['lat'],
                    'lng'=>$payload['lng'],
                    'price_per_unit_kobo'=>$payload['price_per_unit_kobo'],
                    'available_units'=>$payload['available_units'],
                    'units_sold'=>$payload['units_sold'],
                    'total_units'=>$payload['total_units'],
                    'heat'=>$payload['heat'],
                    'map_color'=>$payload['map_color'],
                ];
            }

            return $payload;
        });
    }

    private function mapPayload(Land $land)
    {
        return [
            'id'=>$land->id,
            'title'=>$land->title,
            'location'=>$land->location,
            'size'=>$land->size,
            'description'=>$land->description,
            'price_per_unit_kobo'=>$land->current_price_per_unit_kobo,
            'total_units'=>$land->total_units,
            'available_units'=>$land->available_units,
            'units_sold'=>$land->total_units-$land->available_units,
            'sold_percentage'=>$land->total_units
                ? round((($land->total_units-$land->available_units)/$land->total_units)*100,2)
                : 0,
            'heat'=>$land->total_units
                ? min(1,round(log10(1+($land->total_units-$land->available_units))/log10(1+$land->total_units),3))
                : 0,
            'map_color'=>$this->getMapColor($land),
            'coordinates'=>$land->coordinates_geojson,
            'lat'=>$land->lat,
            'lng'=>$land->lng,
            'is_available'=>(bool)$land->is_available,
            'images'=>$land->images->map(fn($img)=>['id'=>$img->id,'url'=>Storage::url($img->image_path)]),
        ];
    }

    private function success($data=null,$message='OK'){
        return response()->json(compact('data','message')+['success'=>true]);
    }

    private function error($message,$code=400){
        return response()->json(['success'=>false,'message'=>$message],$code);
    }
}
