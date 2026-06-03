<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RentalProfileRequest;
use App\Http\Requests\Auth\SponsorProfileRequest;
use App\Http\Requests\Auth\UserProfileRequest;
use App\Services\Auth\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    protected OnboardingService $onboardingService;

    public function __construct(OnboardingService $onboardingService)
    {
        $this->onboardingService = $onboardingService;
    }

    /**
     * Complete rental type profile (Onboarding Step 2 for Rental).
     */
    public function saveRentalProfile(RentalProfileRequest $request): JsonResponse
    {
        $rentalProfile = $this->onboardingService->saveRentalProfile(
            $request->user(),
            $request->validated()
        );

        return $this->successResponse($rentalProfile, 'Rental profile updated successfully.');
    }

    /**
     * Complete user personal profile details (Step 3 for Rental, Step 2 for Owner/Sponsor).
     */
    public function saveUserProfile(UserProfileRequest $request): JsonResponse
    {
        // Pass both validated inputs and the potential uploaded photo file
        $data = $request->validated();
        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo');
        }

        $userProfile = $this->onboardingService->saveUserProfile(
            $request->user(),
            $data
        );

        return $this->successResponse($userProfile, 'User profile updated successfully.');
    }

    /**
     * Complete sponsor profile details (Step 3 for Sponsor).
     */
    public function saveSponsorProfile(SponsorProfileRequest $request): JsonResponse
    {
        $sponsorProfile = $this->onboardingService->saveSponsorProfile(
            $request->user(),
            $request->validated()
        );

        return $this->successResponse($sponsorProfile, 'Sponsor profile updated successfully.');
    }

    /**
     * Fetch the authenticated user's full cached profile and onboarding state.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->onboardingService->getProfileDetails($request->user()->id);

        return $this->successResponse($user, 'Profile retrieved successfully.');
    }

    /**
     * Save a single verification checkpoint (e.g. liveness right after selfie).
     * POST /api/auth/profile/verification-checkpoint
     */
    public function saveVerificationCheckpoint(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'check' => 'required|string|in:liveness',
            'passed' => 'required|boolean',
            'result_json' => 'nullable',
        ]);

        if ($data['passed'] && $data['check'] === 'liveness') {
            $user->update(['liveness_passed' => true]);
        }

        $this->onboardingService->clearProfileCache($user->id);

        return $this->successResponse(
            $this->buildIdentityStatusPayload($user->fresh()),
            'Verification checkpoint saved.'
        );
    }

    /**
     * Save OCR / ML verification payload (partial or final). Sticky per-check flags.
     * POST /api/auth/profile/ocr
     */
    public function saveOcrData(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'request_id' => 'required|string',
            'result_json' => 'required',
            'front' => 'nullable|image|max:10240',
            'back' => 'nullable|image|max:10240',
            'selfie' => 'nullable|image|max:10240',
        ]);

        if (is_string($data['result_json'])) {
            $data['result_json'] = json_decode($data['result_json'], true);
        }

        $result = $this->sanitizeUtf8($data['result_json'] ?? []);
        $sections = $result['sections'] ?? [];
        $pipelineStatus = $result['status'] ?? null;
        $isProcessing = $pipelineStatus === 'processing';

        $frontId = $sections['ocr_front']['front_id_number'] ?? $sections['ocr_front']['id_number'] ?? null;
        $backId = $sections['ocr_back']['back_id_number'] ?? null;
        $idNumber = $frontId ?? $backId;
        if ($idNumber && strlen($idNumber) > 14) {
            $idNumber = substr($idNumber, -14);
        }

        $checks = [
            'validation' => (bool) ($sections['validation']['id_valid'] ?? false),
            'face_match' => (bool) ($sections['face_match']['passed'] ?? false),
            'liveness' => ($sections['liveness']['passed'] ?? false) &&
                (($sections['liveness']['status'] ?? 'ok') === 'ok'),
            'ocr_front' => isset($sections['ocr_front']) &&
                !isset($sections['ocr_front']['error']) &&
                !empty($idNumber) &&
                strlen($idNumber) >= 10 &&
                !empty($sections['ocr_front']['name']) &&
                !empty($sections['ocr_front']['birth_date']),
            'ocr_back' => isset($sections['ocr_back']) &&
                !isset($sections['ocr_back']['error']) &&
                !empty($sections['ocr_back']['gender']),
        ];

        $confirmed = $this->cumulativeConfirmedChecks($user, $checks);
        $remainingChecks = array_keys(array_filter($confirmed, fn ($v) => !$v));

        $frontPath = $request->file('front')?->store("identity/{$user->id}", 'public');
        $backPath = $request->file('back')?->store("identity/{$user->id}", 'public');
        $selfiePath = $request->file('selfie')?->store("identity/{$user->id}", 'public');

        $ocrLocked = $this->persistOcrProfileFields($user, $sections, $idNumber);

        $coreThreePassed = $confirmed['validation'] && $confirmed['face_match']
            && $confirmed['ocr_front'] && $confirmed['ocr_back'];
        $fullyVerified = $coreThreePassed && $ocrLocked;

        $overallStatus = $isProcessing ? 'pending' : ($fullyVerified ? 'completed' : 'failed');

        $verification = \App\Models\IdentityVerification::firstOrNew(
            ['request_id' => $data['request_id']]
        );
        if (!$verification->exists) {
            $verification->submitted_at = now();
        }
        $verification->fill([
            'user_id' => $user->id,
            'overall_status' => $overallStatus,
            'admin_review_status' => $fullyVerified ? null : 'pending',
            'validation_passed' => $checks['validation'],
            'face_match_passed' => $checks['face_match'],
            'liveness_passed' => $checks['liveness'],
            'ocr_front_passed' => $checks['ocr_front'],
            'ocr_back_passed' => $checks['ocr_back'],
            'id_number' => $idNumber,
            'extracted_name' => $sections['ocr_front']['name'] ?? null,
            'birth_date' => $sections['ocr_front']['birth_date'] ?? null,
            'address' => $sections['ocr_front']['address'] ?? null,
            'gender' => $sections['ocr_back']['gender'] ?? null,
            'ml_result_json' => $result,
            'front_image_path' => $frontPath ?? $verification->front_image_path,
            'back_image_path' => $backPath ?? $verification->back_image_path,
            'selfie_image_path' => $selfiePath ?? $verification->selfie_image_path,
            'completed_at' => $fullyVerified ? now() : $verification->completed_at,
        ]);
        $verification->save();

        $userUpdate = [];
        if ($confirmed['liveness']) {
            $userUpdate['liveness_passed'] = true;
        }
        if ($confirmed['face_match']) {
            $userUpdate['face_match_passed'] = true;
        }
        if (isset($sections['ocr_back']['gender']) && in_array($sections['ocr_back']['gender'], ['male', 'female'], true)) {
            $userUpdate['gender'] = $sections['ocr_back']['gender'];
        }
        $userUpdate['is_verified'] = $fullyVerified;
        $user->update($userUpdate);

        $this->onboardingService->clearProfileCache($user->id);
        $fresh = $user->fresh();

        $payload = $this->buildIdentityStatusPayload($fresh);
        $payload['success'] = $fullyVerified;
        $payload['pipeline_status'] = $pipelineStatus ?? ($fullyVerified ? 'completed' : 'failed');
        $payload['verification_id'] = $verification->id;
        $payload['this_attempt'] = $checks;
        $payload['confirmed_checks'] = $this->publicConfirmedChecks($confirmed);
        $payload['failed_checks'] = $this->publicRemainingChecks($confirmed);
        $payload['remaining_checks'] = $payload['failed_checks'];
        $payload['can_retry'] = !$fullyVerified;
        $payload['user'] = [
            'is_verified' => $fresh->is_verified,
            'liveness_passed' => $fresh->liveness_passed,
            'face_match_passed' => $fresh->face_match_passed,
            'gender' => $fresh->gender,
        ];

        $message = $isProcessing
            ? 'Verification still processing; partial results saved.'
            : ($fullyVerified
                ? 'Identity verified successfully'
                : 'Verification incomplete: ' . implode(', ', $payload['remaining_checks']) . ' still required');

        return $this->successResponse($payload, $message);
    }

    /**
     * Persist OCR fields to profile; lock ID / DOB / expiry / gender when complete.
     *
     * @return bool Whether OCR fields are locked on the profile
     */
    private function persistOcrProfileFields(\App\Models\User $user, array $sections, ?string $idNumber): bool
    {
        $ocrFront = $sections['ocr_front'] ?? [];
        $ocrBack = $sections['ocr_back'] ?? [];

        $profileFields = array_filter([
            'id_number' => $idNumber ?? ($ocrFront['id_number'] ?? null),
            'birth_date' => $ocrFront['birth_date'] ?? null,
            'address' => $ocrFront['address'] ?? null,
            'id_expiry_date' => $ocrBack['expiry_date'] ?? null,
            'id_issue_date' => $ocrBack['issue_date'] ?? null,
            'profession' => $ocrBack['profession'] ?? null,
            'religion' => $ocrBack['religion'] ?? null,
            'marital_status' => $ocrBack['marital_status'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $name = $ocrFront['name'] ?? null;
        if ($name) {
            $parts = preg_split('/\s+/', trim($name), 2);
            $profileFields['first_name'] = $parts[0] ?? $name;
            $profileFields['last_name'] = $parts[1] ?? '';
        }

        if (empty($profileFields)) {
            return (bool) $user->profile?->identity_ocr_locked;
        }

        $profile = $user->profile;
        if (!$profile) {
            $profile = $user->profile()->create($profileFields);
        } elseif (!$profile->identity_ocr_locked) {
            $profile->fill($profileFields)->save();
        }

        $profile->refresh();
        $genderOk = in_array($user->fresh()->gender ?? '', ['male', 'female'], true)
            || in_array($ocrBack['gender'] ?? '', ['male', 'female'], true);

        $locked = !empty($profile->id_number)
            && !empty($profile->birth_date)
            && !empty($profile->id_expiry_date)
            && $genderOk;

        if ($locked && !$profile->identity_ocr_locked) {
            $profile->update(['identity_ocr_locked' => true]);
        }

        return (bool) $profile->fresh()->identity_ocr_locked;
    }

    /**
     * @param  array<string,bool>  $confirmed
     * @return array<string,bool>
     */
    private function publicConfirmedChecks(array $confirmed): array
    {
        return [
            'validation' => $confirmed['validation'] ?? false,
            'face_match' => $confirmed['face_match'] ?? false,
            'ocr' => ($confirmed['ocr_front'] ?? false) && ($confirmed['ocr_back'] ?? false),
            'liveness' => $confirmed['liveness'] ?? false,
        ];
    }

    /**
     * @param  array<string,bool>  $confirmed
     * @return list<string>
     */
    private function publicRemainingChecks(array $confirmed): array
    {
        $public = $this->publicConfirmedChecks($confirmed);

        return array_keys(array_filter($public, fn ($v) => !$v));
    }

    /**
     * @return array<string,mixed>
     */
    public function buildIdentityStatusPayload(\App\Models\User $user): array
    {
        $all = \App\Models\IdentityVerification::where('user_id', $user->id)->get();
        $latest = $all->sortByDesc('submitted_at')->first();

        $confirmed = [
            'validation' => $all->contains('validation_passed', true),
            'face_match' => $all->contains('face_match_passed', true) || (bool) $user->face_match_passed,
            'liveness' => $all->contains('liveness_passed', true) || (bool) $user->liveness_passed,
            'ocr_front' => $all->contains('ocr_front_passed', true),
            'ocr_back' => $all->contains('ocr_back_passed', true),
        ];

        $ocrLocked = (bool) $user->profile?->identity_ocr_locked;
        $coreThree = $confirmed['validation'] && $confirmed['face_match']
            && $confirmed['ocr_front'] && $confirmed['ocr_back'];
        $fullyVerified = $coreThree && $ocrLocked;

        if ($user->is_verified !== $fullyVerified) {
            $user->update(['is_verified' => $fullyVerified]);
            $user->refresh();
        }

        $publicConfirmed = $this->publicConfirmedChecks($confirmed);
        $remaining = $this->publicRemainingChecks($confirmed);

        return [
            'has_verification' => $latest !== null,
            'overall_status' => $fullyVerified ? 'completed' : ($latest?->overall_status ?? 'none'),
            'pipeline_status' => ($latest?->ml_result_json['status'] ?? null),
            'confirmed_checks' => $publicConfirmed,
            'remaining_checks' => $remaining,
            'failed_checks' => $remaining,
            'is_verified' => $fullyVerified,
            'is_id_verified' => $fullyVerified,
            'identity_ocr_locked' => $ocrLocked,
            'ocr_fields' => [
                'id_number' => $user->profile?->id_number,
                'birth_date' => $user->profile?->birth_date,
                'id_expiry_date' => $user->profile?->id_expiry_date,
                'gender' => $user->gender,
            ],
            'can_retry' => !$fullyVerified,
            'latest_verification_id' => $latest?->id,
            'latest_request_id' => $latest?->request_id,
            'message' => !$ocrLocked && ($confirmed['ocr_front'] || $confirmed['ocr_back'])
                ? 'ID data is processing — you are not fully verified until OCR values are saved.'
                : ($fullyVerified ? 'Identity fully verified.' : null),
        ];
    }

    /**
     * Compute the cumulative ("sticky") confirmation state of every identity check for a
     * user, merging the current attempt with what was already confirmed previously.
     *
     * @param  array<string,bool>  $currentChecks
     * @return array<string,bool>
     */
    private function cumulativeConfirmedChecks(\App\Models\User $user, array $currentChecks = []): array
    {
        $prior = \App\Models\IdentityVerification::where('user_id', $user->id)->get();

        return [
            'validation' => ($currentChecks['validation'] ?? false) || $prior->contains('validation_passed', true),
            'face_match' => ($currentChecks['face_match'] ?? false) || $prior->contains('face_match_passed', true) || (bool) $user->face_match_passed,
            'liveness'   => ($currentChecks['liveness'] ?? false) || $prior->contains('liveness_passed', true) || (bool) $user->liveness_passed,
            'ocr_front'  => ($currentChecks['ocr_front'] ?? false) || $prior->contains('ocr_front_passed', true),
            'ocr_back'   => ($currentChecks['ocr_back'] ?? false) || $prior->contains('ocr_back_passed', true),
        ];
    }

    /**
     * Recursively coerce every string in a structure to valid UTF-8, dropping malformed
     * byte sequences so the value can be safely json_encoded / stored in a JSON column.
     */
    private function sanitizeUtf8(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->sanitizeUtf8($v), $value);
        }

        if (is_string($value)) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $value;
    }
}
