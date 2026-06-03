<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDetail extends Model
{
    protected $primaryKey = 'rental_profile_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'rental_profile_id',
        'company',
        'job_title',
    ];

    public function rentalProfile(): BelongsTo
    {
        return $this->belongsTo(RentalProfile::class, 'rental_profile_id');
    }
}
