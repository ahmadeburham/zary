<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\OnboardingController;
use App\Http\Controllers\ApartmentController;
use App\Http\Controllers\ApartmentMembersController;
use App\Http\Controllers\AdminVerificationController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminManagementController;
use App\Http\Controllers\ContractsController;
use App\Http\Controllers\IdentityVerificationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OwnerProfileController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProxyController;
use App\Http\Controllers\RecommenderController;
use App\Http\Controllers\TenantContractController;
use App\Http\Controllers\ManualVerificationController;
use App\Http\Controllers\AdminSqlController;
use Illuminate\Support\Facades\Route;

// ── Auth (public) ─────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register',         [AuthController::class, 'register']);
    Route::post('/login',            [AuthController::class, 'login']);
    Route::post('/social-login',     [AuthController::class, 'socialLogin']);
    Route::post('/verify-otp',       [AuthController::class, 'verifyOtp']);
    Route::post('/resend-otp',       [AuthController::class, 'resendOtp']);
    Route::post('/forgot-password',  [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',   [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout',            [AuthController::class, 'logout']);
        Route::post('/token/refresh',     [AuthController::class, 'refreshToken']);
        Route::post('/token/invalidate',  [AuthController::class, 'invalidateToken']);
        Route::post('/change-password',   [AuthController::class, 'changePassword']);
        Route::post('/fcm-token',         [AuthController::class, 'updateFcmToken']);
        Route::get('/me',                 [OnboardingController::class, 'me']);
        Route::post('/profile/ocr',       [OnboardingController::class, 'saveOcrData']);
        Route::post('/profile/verification-checkpoint', [OnboardingController::class, 'saveVerificationCheckpoint']);

        Route::prefix('onboarding')->group(function () {
            Route::post('/rental-profile', [OnboardingController::class, 'saveRentalProfile']);
            Route::post('/user-profile',   [OnboardingController::class, 'saveUserProfile']);
            Route::post('/sponsor-profile',[OnboardingController::class, 'saveSponsorProfile']);
        });
    });
});

// ── Public webhook ────────────────────────────────────────────────────────────
Route::post('/payments/webhook/paymob',         [PaymentController::class, 'handleWebhook']);
Route::post('/paymob/webhook',                  [PaymentController::class, 'handleWebhook']);
Route::get('/acceptance/post_pay',              [PaymentController::class, 'handlePostPay']);

// Debug endpoint to test Paymob connection (remove in production)
Route::get('/debug/paymob-test', function () {
    $service = new \App\Services\PaymobService();
    $token = $service->getAuthToken();

    return response()->json([
        'status' => $token ? 'success' : 'failed',
        'token_obtained' => $token ? true : false,
        'env_check' => [
            'api_key_set' => !empty(env('PAYMOB_API_KEY')),
            'integration_id_set' => !empty(env('PAYMOB_INTEGRATION_ID')),
            'iframe_id_set' => !empty(env('PAYMOB_IFRAME_ID')),
            'base_url' => env('PAYMOB_BASE_URL', 'not set'),
        ],
        'message' => $token ? 'Paymob connection successful' : 'Failed to get Paymob auth token - check API key',
    ]);
});

// ── Authenticated routes ──────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ── ML / Recommender proxy ────────────────────────────────────────────────
    Route::any('/ml/{path}',          [ProxyController::class, 'forwardToMl'])->where('path', '.*');
    Route::any('/recommender/{path}', [ProxyController::class, 'forwardToRecommender'])->where('path', '.*');

    // Recommendations API
    Route::get('/recommendation-faculties', [RecommenderController::class, 'faculties']);
    Route::post('/recommendations', [RecommenderController::class, 'recommend']);
    Route::get('/universities', [\App\Http\Controllers\UniversityController::class, 'index']);
    Route::get('/universities/{id}/programs', [\App\Http\Controllers\UniversityController::class, 'programs']);

    // ── Apartments ────────────────────────────────────────────────────────────
    Route::apiResource('apartments', ApartmentController::class);
    Route::post('/apartments/{id}/join',          [ApartmentController::class, 'join']);
    Route::post('/apartments/{id}/leave',         [ApartmentController::class, 'leave']);

    // Apartment lease template
    Route::get('/apartments/{id}/lease-template', [ApartmentController::class, 'leaseTemplate']);

    // Apartment members
    Route::get('/apartments/{id}/members',        [ApartmentMembersController::class, 'index']);
    Route::post('/apartments/{id}/add-member',    [ApartmentMembersController::class, 'add']);
    Route::post('/apartments/{id}/remove-member', [ApartmentMembersController::class, 'remove']);

    // Per-apartment contracts
    Route::get('/apartments/{apartmentId}/contracts',    [ContractsController::class, 'showForApartment']);
    Route::delete('/apartments/{apartmentId}/contracts', [ContractsController::class, 'destroyForApartment']);

    // ── Contracts ─────────────────────────────────────────────────────────────
    Route::get('/contracts',               [ContractsController::class, 'tenantIndex']);
    Route::post('/contracts',              [TenantContractController::class, 'uploadContract']);
    Route::get('/contracts/owner',         [ContractsController::class, 'ownerIndex']);
    Route::get('/contracts/{id}',          [ContractsController::class, 'show']);
    Route::put('/contracts/{id}',          [ContractsController::class, 'update']);
    Route::delete('/contracts/{id}',       [ContractsController::class, 'destroy']);
    Route::post('/contracts/{id}/accept',  [ContractsController::class, 'accept']);
    Route::post('/contracts/{id}/refuse',  [ContractsController::class, 'refuse']);

    // ── Owner profile ─────────────────────────────────────────────────────────
    Route::post('/owner/payout',             [OwnerProfileController::class, 'updatePayout']);
    Route::post('/owner/identity-document',  [OwnerProfileController::class, 'uploadIdentityDocument']);
    Route::get('/owners',                    [PaymentController::class, 'listOwners']);

    // ── Tenant profile ────────────────────────────────────────────────────────
    Route::post('/tenant/identity-document', [OwnerProfileController::class, 'uploadIdentityDocument']);
    Route::get('/tenant/membership',         [TenantContractController::class, 'myMembership']);
    Route::post('/tenant/contracts',         [TenantContractController::class, 'uploadContract']);
    Route::post('/tenant/refund',            [PaymentController::class, 'requestRefund']);
    Route::get('/tenant/payment-status',     [PaymentController::class, 'getPaymentStatus']);

    // Identity documents (bulk upload from document_verification_screen)
    Route::post('/identity/documents',       [OwnerProfileController::class, 'uploadIdentityDocuments']);

    // Manual verification request (tenant)
    Route::post('/identity/manual-verification-request', [ManualVerificationController::class, 'store']);

    // Identity verification history and status
    Route::get('/identity/verifications', [IdentityVerificationController::class, 'index']);
    Route::get('/identity/status', [IdentityVerificationController::class, 'status']);

    // ── Payments ──────────────────────────────────────────────────────────────
    Route::prefix('payment')->group(function () {
        Route::get('/orders',               [PaymentController::class, 'listOrders']);
        Route::get('/orders/{id}',          [PaymentController::class, 'showOrder']);
        Route::post('/orders/{id}/sync',       [PaymentController::class, 'syncOrderFromPaymob']);
        Route::post('/orders/{id}/retry',      [PaymentController::class, 'retryPaymentLink']);
        Route::post('/orders/{id}/dummy-pay',  [PaymentController::class, 'dummyPay']);
        Route::get('/transactions',         [PaymentController::class, 'listTransactions']);
        Route::get('/refund-requests',      [PaymentController::class, 'listMyRefunds']);
        Route::post('/refund-requests',     [PaymentController::class, 'submitRefund']);
    });

    // ── Notifications ─────────────────────────────────────────────────────────
    Route::get('/notifications',              [NotificationController::class, 'index']);
    Route::post('/notifications/read-all',    [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{id}/read',   [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications',           [NotificationController::class, 'destroyAll']);

    // ── Admin ─────────────────────────────────────────────────────────────────
    Route::prefix('admin')->group(function () {
        // Identity documents
        Route::post('/identity-documents/{id}/verify',  [AdminVerificationController::class, 'verifyIdentityDocument']);
        Route::post('/identity-documents/{id}/reject',  [AdminVerificationController::class, 'rejectIdentityDocument']);
        Route::delete('/identity-documents/{id}',       [AdminVerificationController::class, 'deleteIdentityDocument']);
        Route::get('/identity-documents/pending',       [AdminVerificationController::class, 'getPendingIdentityDocuments']);
        Route::get('/identity-verifications/pending', [AdminVerificationController::class, 'getPendingIdentityVerifications']);
        Route::post('/identity-verifications/{id}/review', [AdminVerificationController::class, 'reviewIdentityVerification']);
        Route::post('/identity-documents/{id}/review', [AdminVerificationController::class, 'reviewIdentityDocument']);

        // Apartment documents
        Route::post('/apartment-documents/{id}/verify', [AdminVerificationController::class, 'verifyApartmentDocument']);
        Route::post('/apartment-documents/{id}/reject', [AdminVerificationController::class, 'rejectApartmentDocument']);
        Route::delete('/apartment-documents/{id}',      [AdminVerificationController::class, 'deleteApartmentDocument']);

        // Tenant contracts
        Route::post('/tenant-contracts/{id}/verify',    [AdminVerificationController::class, 'verifyTenantContract']);
        Route::post('/tenant-contracts/{id}/reject',    [AdminVerificationController::class, 'rejectTenantContract']);
        Route::delete('/tenant-contracts/{id}',         [AdminVerificationController::class, 'deleteTenantContract']);

        // Apartments moderation
        Route::get('/apartments/{id}/moderation-details', [AdminVerificationController::class, 'getApartmentModerationDetails']);
        Route::post('/apartments/{id}/verify',            [AdminVerificationController::class, 'verifyApartment']);
        Route::post('/apartments/{id}/refuse',            [AdminVerificationController::class, 'refuseApartment']);
        Route::post('/apartments/{id}/retrigger-payment', [AdminVerificationController::class, 'retriggerPayment']);

        // Refunds
        Route::get('/refund-requests',             [PaymentController::class, 'listAllRefunds']);
        Route::post('/refund-requests/{id}/approve',[PaymentController::class, 'approveRefund']);
        Route::post('/refund-requests/{id}/reject', [PaymentController::class, 'rejectRefund']);

        // Contracts (admin view)
        Route::get('/contracts', [ContractsController::class, 'adminIndex']);

        // Admin custom payment order
        Route::post('/payment/orders', [PaymentController::class, 'adminCreateOrder']);

        // Manual verification requests
        Route::get('/manual-verification-requests',              [ManualVerificationController::class, 'index']);
        Route::post('/manual-verification-requests/{id}/approve',[ManualVerificationController::class, 'approve']);
        Route::post('/manual-verification-requests/{id}/reject', [ManualVerificationController::class, 'reject']);

        // SQL query panel
        Route::post('/query', [AdminSqlController::class, 'query']);

        // User management
        Route::get('/users',                    [AdminUserController::class, 'index']);
        Route::post('/users',                   [AdminUserController::class, 'store']);
        Route::put('/users/{id}',               [AdminUserController::class, 'update']);
        Route::post('/users/{id}/promote',      [AdminUserController::class, 'promote']);
        Route::post('/users/{id}/demote',       [AdminUserController::class, 'demote']);
        Route::delete('/users/{id}',            [AdminUserController::class, 'destroy']);

        // ── Leases Management ───────────────────────────────────────────
        Route::prefix('leases')->group(function () {
            Route::get('/', [AdminManagementController::class, 'getLeases']);
            Route::get('/{id}', [AdminManagementController::class, 'getLease']);
            Route::put('/{id}', [AdminManagementController::class, 'updateLease']);
        });

        // ── Admin Settings ──────────────────────────────────────────────
        Route::prefix('settings')->group(function () {
            Route::get('/', [AdminManagementController::class, 'getSettings']);
            Route::get('/all', [AdminManagementController::class, 'getAllSettings']);
            Route::put('/{key}', [AdminManagementController::class, 'updateSetting']);
            Route::post('/bulk', [AdminManagementController::class, 'bulkUpdateSettings']);
        });
    });
});


