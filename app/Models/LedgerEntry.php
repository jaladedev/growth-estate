<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $fillable = [
        'uid',
        'type',
        'amount_kobo',
        'balance_after',
        'reference',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'uid');
    }
}
