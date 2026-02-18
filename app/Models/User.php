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
        'verification_code',
        'verification_code_expiry',
        'password_reset_code',
        'password_reset_code_expires_at',
        'referred_by',
    ];

    protected $appends = [
        'is_kyc_verified',
        'kyc_status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'            => 'datetime',
        'password'                     => 'hashed',
        'verification_code_expiry'     => 'datetime',
        'password_reset_code_expires_at' => 'datetime',
        'balance_kobo'                 => 'integer',
        'referred_by'                  => 'integer',
    ];

    protected static function booted()
    {
        static::creating(function ($user) {
            $maxAttempts = 10;
            $attempts    = 0;

            do {
                $uid = 'USR-' . strtoupper(Str::random(6));
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    throw new \RuntimeException(
                        'Unable to generate a unique user UID after ' . $maxAttempts . ' attempts.'
                    );
                }
            } while (self::where('uid', $uid)->exists());

            $user->uid = $uid;
        });

        static::created(function (User $user) {
            if (! $user->referral_code) {
                $maxAttempts = 10;
                $attempts    = 0;

                do {
                    $code = strtoupper(substr(md5(uniqid()), 0, 8));
                    $attempts++;

                    if ($attempts >= $maxAttempts) {
                        throw new \RuntimeException(
                            'Unable to generate a unique referral code after ' . $maxAttempts . ' attempts.'
                        );
                    }
                } while (User::where('referral_code', $code)->exists());

                $user->update(['referral_code' => $code]);
            }
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
     |--------------------------------------------------------------------------
     | Relationships
     |--------------------------------------------------------------------------
     */
    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function lands()
    {
        return $this->belongsToMany(Land::class, 'user_land')
            ->withPivot('units')
            ->withTimestamps();
    }

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
        return $this->hasMany(PortfolioAssetSnapshot::class);
    }

    public function latestPortfolioSnapshot()
    {
        return $this->hasOne(PortfolioDailySnapshot::class)
            ->latestOfMany('snapshot_date');
    }

    /*
     |--------------------------------------------------------------------------
     | Wallet Helpers
     |--------------------------------------------------------------------------
     */

    public function deposit(int $amountKobo, string $reference = null): void
    {
        $balanceAfter = $this->balance_kobo + $amountKobo;
        $this->increment('balance_kobo', $amountKobo);
        $this->balance_kobo = $balanceAfter; 

        LedgerEntry::create([
            'user_id'       => $this->id,
            'type'          => 'deposit',
            'amount_kobo'   => $amountKobo,
            'balance_after' => $balanceAfter,
            'reference'     => $reference ?? 'DEP-' . now()->timestamp,
        ]);
    }

    public function withdraw(int $amountKobo, string $reference = null): bool
    {
        if ($this->balance_kobo < $amountKobo) {
            return false;
        }

        $balanceAfter = $this->balance_kobo - $amountKobo;
        $this->decrement('balance_kobo', $amountKobo);
        $this->balance_kobo = $balanceAfter; 
        LedgerEntry::create([
            'user_id'       => $this->id,
            'type'          => 'withdrawal',
            'amount_kobo'   => $amountKobo,
            'balance_after' => $balanceAfter,
            'reference'     => $reference ?? 'WDL-' . now()->timestamp,
        ]);

        return true;
    }


    /*
     |--------------------------------------------------------------------------
     | Referral Relationships
     |--------------------------------------------------------------------------
     */

    public function kycVerification()
    {
        return $this->hasOne(KycVerification::class);
    }

    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function referredUsers()
    {
        return $this->belongsToMany(User::class, 'referrals', 'referrer_id', 'referred_user_id');
    }

    public function referralRewards()
    {
        return $this->hasMany(ReferralReward::class);
    }

    /*
     |--------------------------------------------------------------------------
     | Computed Attributes
     |--------------------------------------------------------------------------
     */

    public function getIsKycVerifiedAttribute(): bool
    {
        return $this->kycVerification?->status === 'approved';
    }

    public function getKycStatusAttribute(): string
    {
        return $this->kycVerification?->status ?? 'not_submitted';
    }
}