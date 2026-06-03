<?php

namespace App\Http\Controllers;

use App\Models\University;
use App\Models\UniversityProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UniversityController extends Controller
{
    /** GET /api/universities */
    public function index(Request $request): JsonResponse
    {
        $city = $request->query('city');
        $q = University::query()->where('is_active', true)->orderBy('name');
        if ($city) {
            $q->where('city', $city);
        }
        return response()->json(['data' => $q->get(['id', 'name', 'name_ar', 'city', 'type', 'latitude', 'longitude'])]);
    }

    /** GET /api/universities/{id}/programs */
    public function programs(string $id): JsonResponse
    {
        $programs = UniversityProgram::query()
            ->where('university_id', $id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'university_id', 'name', 'affinity_group']);

        return response()->json(['data' => $programs]);
    }
}
