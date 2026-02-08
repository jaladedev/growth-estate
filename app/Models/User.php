<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Str;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'balance_kobo',
        'account_number',
        'bank_code',
        'bank_name',
        'account_name',
        'uid',
        'verification_code',
        'verification_code_expiry',
        'password_reset_code',
        'password_reset_code_expires_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'account_number',
        'bank_code',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'verification_code_expiry' => 'datetime',
        'password_reset_code_expires_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($user) {
            do {
                $user->uid = 'USR-' . strtoupper(Str::random(6));
            } while (self::where('uid', $user->uid)->exists());
        });
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    /* 
     | Relationships
    */

    // Wallet ledger
    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class, 'uid', 'id');
    }

    // Transactions (purchases / sales)
     public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    // Owned lands with units
    public function lands()
    {
        return $this->belongsToMany(Land::class, 'user_land')
            ->withPivot('units')
            ->withTimestamps();
    }

    // Direct access to user_land rows
    public function userLands()
    {
        return $this->hasMany(UserLand::class);
    }

    public function portfolioSnapshots()
    {
        return $this->hasMany(PortfolioDailySnapshot::class);
    }

    public function portfolioLandSnapshots()
    {
        return $this->hasMany(PortfolioLandSnapshot::class);
    }

    public function portfolioAssetSnapshots()
    {
        return $this->hasMany(portfolioAssetSnapshots::class);
    }

    public function latestPortfolioSnapshot()
    {
        return $this->hasOne(PortfolioDailySnapshot::class)
            ->latestOfMany('snapshot_date');
    }
    /* 
     | Wallet Logic 
    */

    public function deposit(int $amountKobo, string $reference = null)
    {
        $this->increment('balance_kobo', $amountKobo);

        LedgerEntry::create([
            'uid' => $this->id,
            'type' => 'deposit',
            'amount_kobo' => $amountKobo,
            'balance_after' => $this->balance_kobo,
            'reference' => $reference ?? 'DEP-' . now()->timestamp,
        ]);
    }

    public function withdraw(int $amountKobo, string $reference = null): bool
    {
        if ($this->balance_kobo < $amountKobo) {
            return false;
        }

        $this->decrement('balance_kobo', $amountKobo);

        LedgerEntry::create([
            'uid' => $this->id,
            'type' => 'withdrawal',
            'amount_kobo' => $amountKobo,
            'balance_after' => $this->balance_kobo,
            'reference' => $reference ?? 'WDL-' . now()->timestamp,
        ]);

        return true;
    }

    /* 
     | Email Verification
    */

    public function sendEmailVerificationCode()
    {
        $this->verification_code = Str::random(6);
        $this->verification_code_expiry = now()->addMinutes(30);
        $this->save();

        \Mail::to($this->email)->send(
            new \App\Mail\VerifyEmailMail($this->verification_code)
        );
    }

    public function verifyEmail(string $code): bool
    {
        if (
            $this->verification_code === $code &&
            now()->lessThanOrEqualTo($this->verification_code_expiry)
        ) {
            $this->update([
                'email_verified_at' => now(),
                'verification_code' => null,
                'verification_code_expiry' => null,
            ]);

            return true;
        }

        return false;
    }
}
