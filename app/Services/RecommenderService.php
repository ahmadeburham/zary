<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\FacultyAffinityGroup;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecommenderService
{
    protected string $recommenderUrl;

    public function __construct(
        protected RecommenderDatabaseSync $databaseSync,
    ) {
        $this->recommenderUrl = config('services.recommender.url', 'http://127.0.0.1:8002');
    }

    /**
     * Get personalized apartment recommendations for a user.
     *
     * @param User $user
     * @param int $limit
     * @return array
     * @throws \Exception
     */
    public function getRecommendations(User $user, int $limit = 10): array
    {
        // Get user preferences from student_details
        $preferences = $this->getUserPreferences($user);

        if (!$preferences) {
            throw new \Exception('User preferences not found. Complete your profile first.');
        }

        $affinityGroup = $preferences['affinity_group']
            ?? $this->getAffinityGroup($preferences['faculty'] ?? null);

        $this->databaseSync->sync();

        $budgetMin = (float) ($preferences['budget_min'] ?? 0);
        $budgetMax = (float) ($preferences['budget_max'] ?? 50000);
        if ($budgetMax <= 0) {
            $budgetMax = 50000;
        }
        if ($budgetMin > $budgetMax) {
            [$budgetMin, $budgetMax] = [$budgetMax, $budgetMin];
        }

        $preferredLocation = $preferences['preferred_location']
            ?? $user->profile?->city
            ?? 'Cairo';

        $prefersFurnished = $preferences['prefers_furnished'];
        if ($prefersFurnished !== null) {
            $prefersFurnished = $prefersFurnished ? 1 : 0;
        }

        Log::info('Recommender request', [
            'user_id' => $user->id,
            'preferences' => $preferences,
            'affinity_group' => $affinityGroup,
        ]);

        try {
            $response = Http::timeout(30)->post("{$this->recommenderUrl}/recommend", [
                'budget_min' => $budgetMin,
                'budget_max' => $budgetMax,
                'preferred_location' => $preferredLocation,
                'prefers_furnished' => $prefersFurnished,
                'user_affinity_group' => $affinityGroup,
                'university_latitude' => $preferences['university_latitude'] ?? null,
                'university_longitude' => $preferences['university_longitude'] ?? null,
                'n' => $limit,
            ]);
        } catch (\Exception $e) {
            Log::error('Recommender service connection failed', ['error' => $e->getMessage()]);
            throw new \Exception('Recommender service unavailable. Please try again later.');
        }

        if (!$response->successful()) {
            Log::error('Recommender service error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Recommender service error: ' . $response->body());
        }

        $result = $response->json();

        // Enrich with apartment data from database
        $apartmentIds = collect($result['recommendations'] ?? [])
            ->pluck('apartment_id')
            ->toArray();

        if (empty($apartmentIds)) {
            return [
                'total_ranked' => 0,
                'recommendations' => [],
                'user_preferences' => $preferences,
                'user_affinity_group' => $affinityGroup,
            ];
        }

        $apartments = Apartment::whereIn('id', $apartmentIds)
            ->whereNotIn('status', ['deleted', 'closed'])
            ->with(['photos', 'document', 'owner'])
            ->get()
            ->keyBy('id');

        // Merge and return
        $enriched = collect($result['recommendations'] ?? [])
            ->map(function ($rec) use ($apartments, $user) {
                $apt = $apartments[$rec['apartment_id']] ?? null;
                if (!$apt) {
                    Log::warning('Recommended apartment not found or inactive', [
                        'apartment_id' => $rec['apartment_id'],
                        'user_id' => $user->id,
                    ]);
                    return null;
                }

                return [
                    'apartment' => $apt,
                    'rank' => $rec['rank'] ?? 0,
                    'final_score' => $rec['final_score'] ?? 0,
                    'match_percentage' => round(($rec['final_score'] ?? 0) * 100),
                    'feature_scores' => $rec['feature_scores'] ?? [],
                ];
            })
            ->filter()
            ->values()
            ->toArray();

        return [
            'total_ranked' => $result['total_ranked'] ?? count($enriched),
            'recommendations' => $enriched,
            'user_preferences' => $preferences,
            'user_affinity_group' => $affinityGroup,
        ];
    }

    /**
     * Get user preferences from student_details.
     *
     * @param User $user
     * @return array|null
     */
    private function getUserPreferences(User $user): ?array
    {
        if (!$user->isRental()) {
            return null;
        }

        $rentalProfile = $user->rentalProfile()->with('studentDetails', 'employeeDetails')->first();
        if (!$rentalProfile) {
            return null;
        }

        $studentDetails = $rentalProfile->studentDetails?->load(['universityModel', 'program']);
        if ($studentDetails) {
            $prefs = [
                'budget_min' => $studentDetails->budget_min ?? 0,
                'budget_max' => $studentDetails->budget_max ?? 50000,
                'preferred_location' => $studentDetails->preferred_location,
                'prefers_furnished' => $studentDetails->prefers_furnished,
                'faculty' => $studentDetails->program?->name ?? $studentDetails->faculty,
                'major' => $studentDetails->major,
                'university_latitude' => $studentDetails->university_latitude
                    ?? $studentDetails->universityModel?->latitude,
                'university_longitude' => $studentDetails->university_longitude
                    ?? $studentDetails->universityModel?->longitude,
                'affinity_group' => $studentDetails->program?->affinity_group,
            ];
        } else {
            $prefs = [
                'budget_min' => 0,
                'budget_max' => 50000,
                'preferred_location' => $user->profile?->city ?? 'Cairo',
                'prefers_furnished' => null,
                'faculty' => null,
                'major' => null,
            ];
        }

        if (!$prefs['preferred_location']) {
            $prefs['preferred_location'] = $user->profile?->city ?? 'Cairo';
        }

        if ($prefs['budget_min'] && $prefs['budget_max'] && $prefs['budget_min'] > $prefs['budget_max']) {
            return null;
        }

        return $prefs;
    }

    /**
     * Get affinity group for a faculty.
     *
     * @param string|null $faculty
     * @return string
     */
    private function getAffinityGroup(?string $faculty): string
    {
        if (!$faculty) {
            return 'UNKNOWN';
        }

        $mapping = FacultyAffinityGroup::where('faculty_name', $faculty)->first();
        return $mapping?->affinity_group ?? 'UNKNOWN';
    }
}
