<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SponsorProfile extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'company_name',
        'company_details',
        'target_audience',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
