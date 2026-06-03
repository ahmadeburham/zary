<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class IdempotencyKey extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'operation',
        'resource_type',
        'resource_id',
        'status',
        'request_hash',
        'response',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'response' => 'array',
        'processed_at' => 'datetime',
    ];
}
