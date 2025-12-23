<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\LandImage;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LandController extends Controller
{
    public function index()
    {
        return response()->json(Land::with('images')->where('is_available', true)->get(), 200);
    }

    public function adminIndex()
    {
         return response()->json(Land::with('images')->get(), 200);
    }

    public function show($id)
    {
        $land = Land::with('images')->find($id);
        
        if (!$land) {
            return response()->json([
                'error' => [
                    'message' => 'Land not found',
                    'code' => 'LAND_NOT_FOUND'
                ]
            ], 404);
        }

        return response()->json($land, 200);
    }

    public function store(Request $request)
    {
        // Check if the user is authenticated and is an admin
        if (!auth()->user() || !auth()->user()->is_admin) {
            return response()->json([
                'error' => [
                    'message' => 'Unauthorized action',
                    'code' => 'UNAUTHORIZED'
                ]
            ], 403);
        }

        // Validate incoming request data, including images
        $validatedData = $request->validate([
            'title' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'size' => 'required|numeric',
            'price_per_unit' => 'required|numeric',
            'total_units' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'nullable|image|mimes:jpg,jpeg,png|max:2048', // Validation for multiple images
        ]);

        // Create the Land entry
        $land = Land::create([
            'title' => $validatedData['title'],
            'location' => $validatedData['location'],
            'size' => $validatedData['size'],
            'price_per_unit' => $validatedData['price_per_unit'],
            'total_units' => $validatedData['total_units'],
            'available_units' => $validatedData['total_units'],
            'is_available' => true,
            'description' => $validatedData['description'],
        ]);

        
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                // sanitize: ensure $image is an UploadedFile instance
                if (!$image->isValid()) {
                    continue;
                }
                $imagePath = $image->store('land_images', 'public');

                $land->images()->create([
                    'image_path' => $imagePath
                ]);
            }
        }

        // Build response with full URLs (do not mutate DB fields)
        $land->load('images');
        $images = $land->images->map(function ($img) {
            return [
                'id' => $img->id,
                'image_path' => Storage::url($img->image_path),
            ];
        });

        $response = [
            'message' => 'Land created successfully',
            'land' => $land->toArray(),
            'images' => $images,
        ];

        return response()->json($response, 201);
        }


    public function buy(Request $request, $id)
    {
        $land = Land::find($id);

        if (!$land) {
            return response()->json([
                'error' => [
                    'message' => 'Land not found',
                    'code' => 'LAND_NOT_FOUND'
                ]
            ], 404);
        }

        // Validate incoming request data for purchase
        $validatedData = $request->validate([
            'units' => 'required|integer|min:1',
            'buyer_id' => 'required|integer',
        ]);

        // Check if land is available for purchase
        if (!$land->is_available) {
            return response()->json([
                'error' => [
                    'message' => 'Land is no longer available for purchase',
                    'code' => 'LAND_NOT_AVAILABLE'
                ]
            ], 400);
        }

        $purchase_units = $validatedData['units'];

        // Check if enough units are available
        if ($land->available_units < $purchase_units) {
            return response()->json([
                'error' => [
                    'message' => 'Not enough units available',
                    'code' => 'INSUFFICIENT_UNITS'
                ]
            ], 400);
        }

        // Deduct the purchased units from available units
        $land->available_units -= $purchase_units;

        // If no units are left, mark the land as not available
        if ($land->available_units <= 0) {
            $land->is_available = false;
        }

        $land->save();

        // Create a transaction record
        Transaction::create([
            'land_id' => $land->id,
            'user_id' => $validatedData['buyer_id'],
            'percentage' => $purchase_units,
            'amount' => $purchase_units * $land->price_per_unit,
        ]);

        return response()->json(['message' => 'Purchase successful'], 200);
    }

public function update(Request $request, $id)
{
    $land = Land::with('images')->find($id);

    if (!$land) {
        return response()->json(['message' => 'Land not found'], 404);
    }

    // Validate request
    $validated = $request->validate([
        'title'           => 'sometimes|string|max:255',
        'location'        => 'sometimes|string|max:255',
        'size'            => 'sometimes|numeric',
        'price_per_unit'  => 'sometimes|numeric',
        'total_units'     => 'sometimes|integer|min:1',
        'description'     => 'nullable|string',
        'is_available'    => 'sometimes|boolean',
        'images'          => 'nullable|array',
        'images.*'        => 'image|mimes:jpg,jpeg,png|max:2048',
        'remove_images'   => 'nullable|array',
    ]);

    // Cast numeric/boolean
    if (isset($validated['size'])) $validated['size'] = (float)$validated['size'];
    if (isset($validated['price_per_unit'])) $validated['price_per_unit'] = (float)$validated['price_per_unit'];
    if (isset($validated['total_units'])) $validated['total_units'] = (int)$validated['total_units'];
    if (isset($validated['is_available'])) $validated['is_available'] = (bool)$validated['is_available'];

    // Update land fields
    $land->update(collect($validated)->except(['images', 'remove_images'])->toArray());

    // Remove images
    if (!empty($validated['remove_images'])) {
        $images = LandImage::whereIn('id', $validated['remove_images'])->get();
        foreach ($images as $img) {
            Storage::disk('public')->delete($img->image_path);
            $img->delete();
        }
    }

    // Add new images
    if ($request->hasFile('images')) {
        foreach ($request->file('images') as $image) {
            if (!$image->isValid()) continue;
            $path = $image->store('land_images', 'public');
            $land->images()->create(['image_path' => $path]);
        }
    }

    $land->load('images');

    $images = $land->images->map(fn($img) => [
        'id' => $img->id,
        'image_path' => Storage::url($img->image_path),
    ]);

    return response()->json([
        'message' => 'Land updated successfully',
        'land' => $land->toArray(),
        'images' => $images
    ], 200);
}



   public function disable($id)
    {
        $land = Land::find($id);

        if (! $land) {
            return response()->json(['message' => 'Land not found'], 404);
        }

        $land->is_available = false;
        $land->save();

        return response()->json(['message' => 'Land disabled']);
    }

    public function enable($id)
    {
        $land = Land::find($id);

        if (! $land) {
            return response()->json(['message' => 'Land not found'], 404);
        }

        $land->is_available = true;
        $land->save();

        return response()->json(['message' => 'Land enabled']);
    }

}
