<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\OnboardingController;
use App\Models\IdentityVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityVerificationController extends Controller
{
    /**
     * Get user's identity verification history.
     * GET /api/identity/verifications
     */
    public function index(Request $request): JsonResponse
    {
        $verifications = IdentityVerification::where('user_id', $request->user()->id)
            ->orderByDesc('submitted_at')
            ->get([
                'id',
                'overall_status',
                'validation_passed',
                'face_match_passed',
                'liveness_passed',
                'ocr_front_passed',
                'ocr_back_passed',
                'id_number',
                'extracted_name',
                'submitted_at',
                'completed_at'
            ]);

        return response()->json([
            'data' => $verifications,
            'latest_status' => $verifications->first()?->overall_status ?? 'none',
        ]);
    }

    /**
     * Get latest verification status (lightweight).
     * GET /api/identity/status
     */
    public function status(Request $request): JsonResponse
    {
        $payload = app(OnboardingController::class)
            ->buildIdentityStatusPayload($request->user());

        return response()->json($payload);
    }
}
