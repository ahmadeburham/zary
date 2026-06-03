<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class IdentityVerification extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'request_id',
        'overall_status',
        'validation_passed',
        'face_match_passed',
        'liveness_passed',
        'ocr_front_passed',
        'ocr_back_passed',
        'id_number',
        'extracted_name',
        'birth_date',
        'address',
        'gender',
        'ml_result_json',
        'front_image_path',
        'back_image_path',
        'selfie_image_path',
        'admin_review_status',
        'submitted_at',
        'completed_at',
    ];

    protected $casts = [
        'ml_result_json' => 'array',
        'validation_passed' => 'boolean',
        'face_match_passed' => 'boolean',
        'liveness_passed' => 'boolean',
        'ocr_front_passed' => 'boolean',
        'ocr_back_passed' => 'boolean',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
