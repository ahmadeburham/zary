<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\SocialLoginRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Register a new user account.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return $this->successResponse(
            $result,
            'Registration successful. Verification OTP sent.',
            201
        );
    }

    /**
     * Login using email or phone.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        return $this->successResponse($result, 'Login successful.');
    }

    /**
     * Verify registration OTP.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            $identifier = $request->input('email') ?: $request->input('phone');
            $field = $request->has('email') ? 'email' : 'phone';
            $user = User::where($field, $identifier)->first();
        }

        if (!$user) {
            return $this->errorResponse('User not found.', 404);
        }

        $verified = $this->authService->verifyOtp($user, $request->input('code'));

        if (!$verified) {
            return $this->errorResponse('Invalid or expired OTP.', 400);
        }

        return $this->successResponse(null, 'Account verified successfully.');
    }

    /**
     * Resend registration OTP.
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required_without:email|nullable|string',
            'email' => 'required_without:phone|nullable|email',
        ]);

        $user = $request->user();

        if (!$user) {
            $identifier = $request->input('email') ?: $request->input('phone');
            $field = $request->has('email') ? 'email' : 'phone';
            $user = User::where($field, $identifier)->first();
        }

        if (!$user) {
            return $this->errorResponse('User not found.', 404);
        }

        $this->authService->generateAndSendOtp($user);

        return $this->successResponse(null, 'OTP resent successfully.');
    }

    /**
     * Social Authentication (Google/Facebook).
     */
    public function socialLogin(SocialLoginRequest $request): JsonResponse
    {
        $result = $this->authService->socialLogin($request->validated());

        return $this->successResponse($result, 'Social login successful.');
    }

    /**
     * Logout current session.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->successResponse(null, 'Logged out successfully.');
    }

    /**
     * Refresh the current active Sanctum token.
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Revoke the token that was used to authenticate the current request
        $user->currentAccessToken()->delete();
        
        $newToken = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse([
            'token' => $newToken,
        ], 'Token refreshed successfully.');
    }

    /**
     * Invalidate the current active Sanctum token.
     */
    public function invalidateToken(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $user->currentAccessToken()->delete();

        return $this->successResponse(null, 'Token invalidated successfully.');
    }

    /**
     * Initiate forget password flow.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->forgotPassword($request->validated());

        return $this->successResponse(null, 'Password reset OTP sent to SMS and email.');
    }

    /**
     * Verify OTP and reset password.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword($request->validated());

        return $this->successResponse(null, 'Password reset successfully.');
    }

    /**
     * Change authenticated user password.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->authService->changePassword($request->user(), $request->validated());

        return $this->successResponse(null, 'Password changed successfully.');
    }

    /**
     * Update the authenticated user's FCM token.
     */
    public function updateFcmToken(Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => 'nullable|string',
        ]);

        $request->user()->update([
            'fcm_token' => $request->input('fcm_token'),
        ]);

        return $this->successResponse(null, 'FCM token updated successfully.');
    }
}
