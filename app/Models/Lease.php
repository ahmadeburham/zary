<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lease extends Model
{
    use HasFactory;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'apartment_id',
        'owner_id',
        'tenant_id',
        'contract_id',
        'status',
        'start_date',
        'end_date',
        'monthly_rent',
        'security_deposit',
        'rent_frequency',
        'terms',
        'special_conditions',
        'signed_by_owner_at',
        'signed_by_tenant_at',
        'activated_at',
        'auto_renew',
        'renewal_notice_date',
        'termination_reason',
        'terminated_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'monthly_rent' => 'decimal:2',
        'security_deposit' => 'decimal:2',
        'special_conditions' => 'array',
        'signed_by_owner_at' => 'datetime',
        'signed_by_tenant_at' => 'datetime',
        'activated_at' => 'datetime',
        'auto_renew' => 'boolean',
        'renewal_notice_date' => 'date',
        'terminated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LeasePayment::class)->orderBy('due_date');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->where('status', 'active')
            ->where('end_date', '<=', now()->addDays($days))
            ->where('end_date', '>=', now());
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'active')
            ->where('end_date', '<', now());
    }

    public function isFullySigned(): bool
    {
        return $this->signed_by_owner_at !== null && $this->signed_by_tenant_at !== null;
    }

    public function daysUntilExpiry(): int
    {
        return now()->diffInDays($this->end_date, false);
    }
}
