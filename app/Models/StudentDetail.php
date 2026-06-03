<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDetail extends Model
{
    protected $primaryKey = 'rental_profile_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'rental_profile_id',
        'university_id',
        'university_program_id',
        'university',
        'faculty',
        'major',
        'budget_min',
        'budget_max',
        'preferred_location',
        'prefers_furnished',
        'university_latitude',
        'university_longitude',
    ];

    protected $casts = [
        'budget_min' => 'decimal:2',
        'budget_max' => 'decimal:2',
        'prefers_furnished' => 'boolean',
        'university_latitude' => 'float',
        'university_longitude' => 'float',
    ];

    public function rentalProfile(): BelongsTo
    {
        return $this->belongsTo(RentalProfile::class, 'rental_profile_id');
    }

    public function universityModel(): BelongsTo
    {
        return $this->belongsTo(University::class, 'university_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(UniversityProgram::class, 'university_program_id');
    }
}
