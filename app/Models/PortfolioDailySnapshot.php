<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioDailySnapshot extends Model
{
    protected $table = 'portfolio_daily_snapshots';

    public $timestamps = false; // immutable

    protected $fillable = [
        'user_id',
        'snapshot_date',
        'total_units',
        'total_invested_kobo',
        'total_portfolio_value_kobo',
        'profit_loss_kobo',
        'profit_loss_percent',
        'created_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'created_at' => 'datetime',
        'profit_loss_percent' => 'decimal:4',
    ];

    /**
     * Snapshot owner
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
