<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Apartment extends Model
{
    use HasUuids;

    protected $fillable = [
        'owner_id',
        'price',
        'insurance',
        'capacity',
        'male_count',
        'female_count',
        'gender_allowed',
        'rooms_count',
        'beds_count',
        'has_ac',
        'has_water',
        'has_gas',
        'is_furnished',
        'status',
        'verification_status',
        'rent_duration',
        'rented_at',
        'latitude',
        'longitude',
        'location_label',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'insurance' => 'decimal:2',
        'capacity' => 'integer',
        'male_count' => 'integer',
        'female_count' => 'integer',
        'rooms_count' => 'integer',
        'beds_count' => 'integer',
        'has_ac' => 'boolean',
        'has_water' => 'boolean',
        'has_gas' => 'boolean',
        'is_furnished' => 'boolean',
        'rent_duration' => 'integer',
        'rented_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'location_label' => 'string',
    ];

    public function scopeWithLatLng($query)
    {
        return $query;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ApartmentPhoto::class);
    }

    public function document(): HasOne
    {
        return $this->hasOne(ApartmentDocument::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ApartmentMember::class);
    }

    public function tenantContracts(): HasMany
    {
        return $this->hasMany(TenantContract::class);
    }

    public function rentCycles(): HasMany
    {
        return $this->hasMany(RentCycle::class);
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

    public function toArray()
    {
        $array = parent::toArray();
        $user = auth()->user();
        
        $canSeeExact = false;
        if ($user) {
            if ($user->isAdmin() || $user->id === $this->owner_id) {
                $canSeeExact = true;
            } else {
                $canSeeExact = \App\Models\ApartmentMember::where('apartment_id', $this->id)
                    ->where('user_id', $user->id)
                    ->where('membership_status', 'active')
                    ->exists();
            }
        }

        if (!$canSeeExact) {
            unset($array['owner']);
            if (isset($array['latitude']) && isset($array['longitude'])) {
                // Fuzz location by adding an offset of ~50m to 100m.
                // 0.0007 degrees is roughly 75 meters. We'll use a deterministic fuzzing based on ID hash.
                $hash = crc32($this->id);
                $latOffset = (($hash % 100) / 100000) + 0.0005; // 0.0005 to 0.0015 degrees
                $lngOffset = ((($hash >> 8) % 100) / 100000) + 0.0005;
                
                if ($hash % 2 === 0) $latOffset = -$latOffset;
                if (($hash >> 16) % 2 === 0) $lngOffset = -$lngOffset;
                
                $array['latitude'] = round($this->latitude + $latOffset, 6);
                $array['longitude'] = round($this->longitude + $lngOffset, 6);
                $array['location_is_blurred'] = true;
            }
        } else {
            $array['location_is_blurred'] = false;
        }

        return $array;
    }
}
