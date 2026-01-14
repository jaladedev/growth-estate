<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Land extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'location', 'size', 'price_per_unit', 'total_units',
        'available_units', 'is_available', 'description', 'coordinates',
        'lat', 'lng'
    ];

    protected $attributes = [
        'total_units' => 0,
        'available_units' => 0,
        'is_available' => true,
    ];

    protected $casts = [
        'size' => 'float',
        'price_per_unit' => 'decimal:2',
        'total_units' => 'integer',
        'available_units' => 'integer',
        'is_available' => 'boolean',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_land')
                    ->withPivot('units')
                    ->withTimestamps();
    }

    public function images()
    {
        return $this->hasMany(LandImage::class);
    }

    protected $appends = [
        'units_sold',
        'sold_percentage',
        'map_color'
    ];

    public function getUnitsSoldAttribute()
    {
        return $this->total_units - $this->available_units;
    }

    public function getSoldPercentageAttribute()
    {
        if ($this->total_units === 0) return 0;
        return round(($this->units_sold / $this->total_units) * 100, 2);
    }

    public function getMapColorAttribute()
    {
        return match (true) {
            $this->sold_percentage < 25 => 'green',
            $this->sold_percentage < 50 => 'yellow',
            $this->sold_percentage < 75 => 'orange',
            default => 'red',
        };
    }
}
