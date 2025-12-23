<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Land extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'location', 'size', 'price_per_unit', 'total_units',
        'available_units', 'is_available', 'description'
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
}
