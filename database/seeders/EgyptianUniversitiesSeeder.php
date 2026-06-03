<?php

namespace Database\Seeders;

use App\Models\FacultyAffinityGroup;
use App\Models\University;
use App\Models\UniversityProgram;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EgyptianUniversitiesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = require database_path('seeders/data/egypt_universities.php');

        foreach ($rows as $row) {
            $uni = University::updateOrCreate(
                ['name' => $row['name']],
                [
                    'name_ar' => $row['name_ar'] ?? null,
                    'city' => $row['city'],
                    'type' => $row['type'] ?? 'university',
                    'latitude' => $row['latitude'],
                    'longitude' => $row['longitude'],
                    'is_active' => true,
                ]
            );

            foreach ($row['programs'] as $program) {
                UniversityProgram::updateOrCreate(
                    [
                        'university_id' => $uni->id,
                        'name' => $program['name'],
                    ],
                    [
                        'affinity_group' => $program['affinity_group'] ?? 'UNKNOWN',
                        'is_active' => true,
                    ]
                );

                FacultyAffinityGroup::updateOrCreate(
                    ['faculty_name' => $program['name']],
                    ['affinity_group' => $program['affinity_group'] ?? 'UNKNOWN']
                );
            }
        }
    }
}
