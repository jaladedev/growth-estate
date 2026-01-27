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
}
