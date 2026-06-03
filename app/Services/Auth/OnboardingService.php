<?php

namespace App\Services\Auth;

use App\Models\EmployeeDetail;
use App\Models\RentalProfile;
use App\Models\SponsorProfile;
use App\Models\StudentDetail;
use App\Models\University;
use App\Models\UniversityProgram;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Services\Common\FileStorageService;
use Illuminate\Http\UploadedFile;

class OnboardingService
{
    protected FileStorageService $fileStorageService;

    public function __construct(FileStorageService $fileStorageService)
    {
        $this->fileStorageService = $fileStorageService;
    }

    /**
     * Fetch user profile details. Caches the result by user ID.
     */
    public function getProfileDetails(string $userId): User
    {
        return Cache::remember("auth_user_profile_{$userId}", 3600, function () use ($userId) {
            Log::info("Profile Cache MISS for User ID {$userId}. Loading from database.");
            return User::with([
                'roles',
                'profile',
                'rentalProfile.studentDetails',
                'rentalProfile.employeeDetails',
                'sponsorProfile',
                'identityDocument',
            ])->findOrFail($userId);
        });
    }

    /**
     * Clear user profile cache.
     */
    public function clearProfileCache(string $userId): void
    {
        Cache::forget("auth_user_profile_{$userId}");
        Log::info("Profile Cache INVALIDATED for User ID {$userId}");
    }

    /**
     * Save Rental profile (Onboarding Step 2 for Rental role).
     */
    public function saveRentalProfile(User $user, array $data): RentalProfile
    {
        if (!$user->isRental()) {
            throw ValidationException::withMessages([
                'role' => ['User does not have the Rental role.'],
            ]);
        }

        // Save rental profile
        $rentalProfile = RentalProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['type' => $data['type']]
        );

        $type = $data['type'];

        if ($type === 'student') {
            $uni = null;
            $program = null;
            if (!empty($data['university_id'])) {
                $uni = University::find($data['university_id']);
            }
            if (!empty($data['university_program_id'])) {
                $program = UniversityProgram::find($data['university_program_id']);
            }

            $existingStudent = StudentDetail::where('rental_profile_id', $rentalProfile->id)->first();

            StudentDetail::updateOrCreate(
                ['rental_profile_id' => $rentalProfile->id],
                array_filter([
                    'university_id' => $uni?->id ?? $existingStudent?->university_id,
                    'university_program_id' => $program?->id ?? $existingStudent?->university_program_id,
                    'university' => $uni?->name ?? $data['university'] ?? $existingStudent?->university ?? '',
                    'faculty' => $program?->name ?? $data['faculty'] ?? $existingStudent?->faculty ?? '',
                    'major' => $data['major'] ?? $existingStudent?->major,
                    'budget_min' => $data['budget_min'] ?? $existingStudent?->budget_min,
                    'budget_max' => $data['budget_max'] ?? $existingStudent?->budget_max,
                    'preferred_location' => $data['preferred_location'] ?? $uni?->city ?? $existingStudent?->preferred_location,
                    'prefers_furnished' => $data['prefers_furnished'] ?? $existingStudent?->prefers_furnished,
                    'university_latitude' => $uni?->latitude ?? $existingStudent?->university_latitude,
                    'university_longitude' => $uni?->longitude ?? $existingStudent?->university_longitude,
                ], fn ($v) => $v !== null)
            );
            // Delete employee details if switching
            EmployeeDetail::where('rental_profile_id', $rentalProfile->id)->delete();
        } elseif ($type === 'employee') {
            EmployeeDetail::updateOrCreate(
                ['rental_profile_id' => $rentalProfile->id],
                [
                    'company' => $data['company'],
                    'job_title' => $data['job_title'],
                ]
            );
            // Delete student details if switching
            StudentDetail::where('rental_profile_id', $rentalProfile->id)->delete();
        } else {
            // Delete both if other/prefer not to say
            StudentDetail::where('rental_profile_id', $rentalProfile->id)->delete();
            EmployeeDetail::where('rental_profile_id', $rentalProfile->id)->delete();
        }

        // Update onboarding screen status
        $user->update([
            'onboarding_screen' => 2,
        ]);

        $this->clearProfileCache($user->id);

        Log::info("Rental profile saved for User ID {$user->id}, Type: {$type}");

        return $rentalProfile->load(['studentDetails', 'employeeDetails']);
    }

    /**
     * Save User personal profile (Onboarding step: Step 3 for Rental, Step 2 for Owner/Sponsor).
     */
    public function saveUserProfile(User $user, array $data): UserProfile
    {
        $photoPath = null;
        if (isset($data['photo']) && $data['photo'] instanceof UploadedFile) {
            // Invalidate and delete the previous profile photo to prevent cluttering
            if ($user->profile && $user->profile->photo_path) {
                $this->fileStorageService->delete($user->profile->photo_path);
            }
            $photoPath = $this->fileStorageService->uploadProfilePicture($data['photo'], $user->id);
            Log::info("Photo uploaded for User ID {$user->id}: {$photoPath}");
        }

        $existing = $user->profile;
        $profileData = array_filter([
            'first_name' => $data['first_name'] ?? $existing?->first_name,
            'middle_name' => array_key_exists('middle_name', $data)
                ? $data['middle_name']
                : $existing?->middle_name,
            'last_name' => $data['last_name'] ?? $existing?->last_name,
            'age' => $data['age'] ?? $existing?->age,
            'country' => $data['country'] ?? $existing?->country,
            'city' => $data['city'] ?? $existing?->city,
        ], fn ($v) => $v !== null);

        if ($photoPath) {
            $profileData['photo_path'] = $photoPath;
        }

        if (empty($profileData) && !$photoPath) {
            return $user->profile ?? UserProfile::firstOrCreate(['user_id' => $user->id], [
                'first_name' => 'User',
                'last_name' => '',
                'age' => 18,
                'country' => 'Egypt',
                'city' => 'Cairo',
            ]);
        }

        $userProfile = UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            $profileData
        );

        // Update onboarding progress based on user role
        if ($user->isRental()) {
            // Step 3 completed for Rental
            $user->update([
                'onboarding_screen' => 3,
                'is_profile_completed' => true,
            ]);
        } elseif ($user->isOwner()) {
            // Step 2 completed for Owner (final step)
            $user->update([
                'onboarding_screen' => 2,
                'is_profile_completed' => true,
            ]);
        } elseif ($user->isSponsor()) {
            // Step 2 completed for Sponsor (requires step 3 company details next)
            $user->update([
                'onboarding_screen' => 2,
            ]);
        }

        $this->clearProfileCache($user->id);

        Log::info("User profile saved for User ID {$user->id}");

        return $userProfile;
    }

    /**
     * Save Sponsor Profile details (Onboarding Step 3 for Sponsor role).
     */
    public function saveSponsorProfile(User $user, array $data): SponsorProfile
    {
        if (!$user->isSponsor()) {
            throw ValidationException::withMessages([
                'role' => ['User does not have the Sponsor role.'],
            ]);
        }

        $sponsorProfile = SponsorProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => $data['company_name'],
                'company_details' => $data['company_details'],
                'target_audience' => $data['target_audience'] ?? null,
            ]
        );

        // Update onboarding screen status to final
        $user->update([
            'onboarding_screen' => 3,
            'is_profile_completed' => true,
        ]);

        $this->clearProfileCache($user->id);

        Log::info("Sponsor profile saved for User ID {$user->id}");

        return $sponsorProfile;
    }
}
