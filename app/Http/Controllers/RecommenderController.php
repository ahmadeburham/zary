<?php

namespace App\Http\Controllers;

use App\Models\FacultyAffinityGroup;
use App\Services\RecommenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RecommenderController extends Controller
{
    protected RecommenderService $recommenderService;

    public function __construct(RecommenderService $recommenderService)
    {
        $this->recommenderService = $recommenderService;
    }

    /**
     * Faculties that map to recommender affinity groups (for profile dropdown).
     * GET /api/recommendation-faculties
     */
    public function faculties(): JsonResponse
    {
        $rows = FacultyAffinityGroup::query()
            ->orderBy('faculty_name')
            ->get(['faculty_name', 'affinity_group']);

        return response()->json(['data' => $rows]);
    }

    /**
     * Get personalized apartment recommendations.
     * POST /api/recommendations
     */
    public function recommend(Request $request): JsonResponse
    {
        try {
            $limit = $request->input('limit', 10);
            $result = $this->recommenderService->getRecommendations($request->user(), $limit);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Recommendations error', ['error' => $e->getMessage()]);
            $message = $e->getMessage();
            if (str_contains($message, 'preferences not found')) {
                $message = 'Complete your rental profile (budget, city, and faculty for students) in Edit Profile, then try again.';
            }
            return response()->json([
                'success' => false,
                'error' => $message,
            ], 400);
        }
    }
}
