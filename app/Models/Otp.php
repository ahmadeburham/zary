<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Otp extends Model
{
    use HasUuids;

    protected $table = 'otp';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'code',
        'type',
        'expires_at',
        'attempts',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
