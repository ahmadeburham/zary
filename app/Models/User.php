<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'phone',
        'email',
        'password',
        'gender',
        'is_profile_completed',
        'onboarding_screen',
        'is_verified',
        'facebook_id',
        'google_id',
        'fcm_token',
        'payout_info',
        'payout_type',
        'payout_number',
        'has_paid_platform_fee',
        'liveness_passed',
        'face_match_passed',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'liveness_passed'    => 'boolean',
            'face_match_passed'  => 'boolean',
            'password' => 'hashed',
            'is_profile_completed' => 'boolean',
            'is_verified' => 'boolean',
            'onboarding_screen' => 'integer',
            'has_paid_platform_fee' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function rentalProfile(): HasOne
    {
        return $this->hasOne(RentalProfile::class);
    }

    public function sponsorProfile(): HasOne
    {
        return $this->hasOne(SponsorProfile::class);
    }

    public function identityDocument(): HasOne
    {
        return $this->hasOne(IdentityDocument::class);
    }

    public function identityVerifications(): HasMany
    {
        return $this->hasMany(IdentityVerification::class)->orderByDesc('submitted_at');
    }

    public function latestIdentityVerification(): HasOne
    {
        return $this->hasOne(IdentityVerification::class)->latestOfMany('submitted_at');
    }

    public function apartments(): HasMany
    {
        return $this->hasMany(Apartment::class, 'owner_id');
    }

    public function apartmentMembers(): HasMany
    {
        return $this->hasMany(ApartmentMember::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function otps(): HasMany
    {
        return $this->hasMany(Otp::class);
    }

    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function insurancePayments(): HasMany
    {
        return $this->hasMany(InsurancePayment::class);
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function hasRole(string $roleName): bool
    {
        return $this->roles->contains('role', $roleName);
    }

    public function isRental(): bool
    {
        return $this->hasRole('rental');
    }

    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    public function isSponsor(): bool
    {
        return $this->hasRole('sponsor');
    }

    public function isAdmin(): bool
    {
        return $this->roles()->where('role', 'admin')->exists();
    }
}

