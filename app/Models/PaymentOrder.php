<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentOrder extends Model
{
    use HasUuids;

    protected $fillable = [
        'idempotency_key',
        'rent_cycle_id',
        'apartment_id',
        'user_id',
        'amount_cents',
        'breakdown',
        'paymob_order_id',
        'paymob_payment_key',
        'payment_url',
        'status',
        'paid_at',
        'expires_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'breakdown' => 'array',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function rentCycle(): BelongsTo
    {
        return $this->belongsTo(RentCycle::class);
    }

    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function refundRequest(): HasOne
    {
        return $this->hasOne(RefundRequest::class);
    }

    /** Lookup by Paymob ecommerce order id (always stored/compared as string). */
    public static function findByPaymobOrderId(mixed $paymobOrderId): ?self
    {
        if ($paymobOrderId === null || $paymobOrderId === '') {
            return null;
        }

        return static::where('paymob_order_id', (string) $paymobOrderId)->first();
    }
}
