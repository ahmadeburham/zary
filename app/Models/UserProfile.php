<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class UserProfile extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'first_name',
        'middle_name',
        'last_name',
        'photo_path',
        'age',
        'country',
        'city',
        'id_number',
        'birth_date',
        'profession',
        'religion',
        'marital_status',
        'id_expiry_date',
        'id_issue_date',
        'address',
        'identity_ocr_locked',
    ];

    protected $casts = [
        'identity_ocr_locked' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
