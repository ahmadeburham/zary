<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RentalProfile extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'type',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function studentDetails(): HasOne
    {
        return $this->hasOne(StudentDetail::class, 'rental_profile_id');
    }

    public function employeeDetails(): HasOne
    {
        return $this->hasOne(EmployeeDetail::class, 'rental_profile_id');
    }
}
