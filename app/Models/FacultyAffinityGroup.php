<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacultyAffinityGroup extends Model
{
    use HasFactory;

    protected $primaryKey = 'faculty_name';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['faculty_name', 'affinity_group'];
}
