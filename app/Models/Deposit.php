<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use HasFactory;

    // Define the table name if it’s different from the plural of the model name
    protected $table = 'deposits';

    // Mass assignable attributes
    protected $fillable = [
        'user_id',
        'reference',
        'amount_kobo',
        'transaction_fee',
        'total_kobo',
        'status',
    ];

    protected $casts = [
    'amount_kobo'     => 'integer',
    'transaction_fee' => 'integer',
    'total_kobo'      => 'integer',
    ];

    /**
     * Relationship to the User model
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for completed deposits
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for pending deposits
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for failed deposits
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
