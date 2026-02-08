<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandPriceHistory extends Model
{
    protected $table = 'land_price_history';

    public $timestamps = false;

    protected $fillable = [
        'land_id',
        'price_per_unit_kobo',
        'price_date',
        'created_at',
    ];

    protected $casts = [
        'price_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function land(): BelongsTo
    {
        return $this->belongsTo(Land::class);
    }

    public static function currentPrice(int $landId): self
    {
        return self::where('land_id', $landId)
            ->where('price_date', '<=', now()->toDateString())
            ->orderByDesc('price_date')
            ->firstOrFail();
    }
    /**
     * Get current prices for multiple lands
     * 
     * @param array $landIds
     * @return \Illuminate\Support\Collection
     */

   /**
 * Get current prices for multiple lands
 * 
 * @param array $landIds
 * @return \Illuminate\Support\Collection
 */
public static function currentPricesForLands(array $landIds): \Illuminate\Support\Collection
{
    if (empty($landIds)) {
        return collect();
    }

    $placeholders = implode(',', array_fill(0, count($landIds), '?'));
    
    $results = \DB::select("
        SELECT DISTINCT ON (land_id) 
            id, land_id, price_per_unit_kobo, price_date, created_at
        FROM land_price_history
        WHERE land_id IN ({$placeholders})
          AND price_date <= CURRENT_DATE
        ORDER BY land_id, price_date DESC, created_at DESC
    ", $landIds);
    
    return collect($results)->map(function ($row) {
        $model = new self();
        $model->id = $row->id;
        $model->land_id = $row->land_id;
        $model->price_per_unit_kobo = $row->price_per_unit_kobo;
        $model->price_date = $row->price_date;
        $model->created_at = $row->created_at;
        $model->exists = true;
        
        return $model;
    })->keyBy('land_id');
}
}
