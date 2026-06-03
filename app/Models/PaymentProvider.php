<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'display_name',
        'type',
        'status',
        'config',
        'supported_currencies',
        'transaction_fee_percent',
        'transaction_fee_fixed',
        'min_amount',
        'max_amount',
        'is_default',
        'sort_order',
        'description',
        'logo_url',
    ];

    protected $casts = [
        'config' => 'array',
        'supported_currencies' => 'array',
        'transaction_fee_percent' => 'decimal:2',
        'transaction_fee_fixed' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function calculateFee(float $amount): float
    {
        $fee = ($amount * $this->transaction_fee_percent / 100) + $this->transaction_fee_fixed;
        return round($fee, 2);
    }

    public function getTotalAmount(float $amount): float
    {
        return $amount + $this->calculateFee($amount);
    }
}
