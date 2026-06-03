<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\ApartmentDocument;
use App\Models\IdentityDocument;
use App\Models\IdentityVerification;
use App\Models\Notification;
use App\Models\TenantContract;
use App\Models\ApartmentMember;
use App\Jobs\SendNotification;
use App\Models\RentCycle;
use App\Models\PaymentOrder;
use App\Models\InsurancePayment;
use App\Services\PaymobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AdminVerificationController extends Controller
{
    /**
     * Helper to dispatch FCM notification and save to database.
     */
    protected function notifyUser($userId, string $title, string $body, array $data = [])
    {
        // 1. Create a database record for tracking notifications
        Notification::create([
            'user_id' => $userId,
            'type' => $data['type'] ?? 'verification_update',
            'dedupe_key' => 'verification_' . uniqid(),
            'data' => array_merge(['title' => $title, 'body' => $body], $data),
            'status' => 'pending',
        ]);

        // 2. Dispatch FCM job
        SendNotification::dispatch($userId, $title, $body, $data, ['fcm']);
    }

    /**
     * Approve an owner's identity document.
     */
    public function verifyIdentityDocument(Request $request, $id)
    {
        $doc = IdentityDocument::findOrFail($id);
        
        $doc->update([
            'status' => 'approved',
            'is_verified' => true,
            'rejection_reason' => null
        ]);

        // Mark user as verified (admin approval vouches for full identity)
        $user = $doc->user;
        $user->update(['is_verified' => true, 'liveness_passed' => true, 'face_match_passed' => true]);

        // Clear user profile cache
        Cache::forget("auth_user_profile_{$user->id}");

        $title = "Identity Document Approved";
        $body = "Your identity document has been verified successfully. You can now manage and publish apartments.";
        
        $this->notifyUser($user->id, $title, $body, [
            'type' => 'identity_verified',
            'status' => 'approved',
            'document_id' => $doc->id
        ]);

        // Find if this user is a pending member of any apartment
        $pendingMembership = ApartmentMember::where('user_id', $user->id)
            ->where('membership_status', 'pending')
            ->first();

        if ($pendingMembership) {
            $this->checkAndTriggerPaymentFlow($pendingMembership->apartment_id);
        }

        return response()->json([
            'message' => 'Identity document approved and user verified.',
            'data' => $doc
        ]);
    }

    /**
     * Reject an owner's identity document with a reason.
     */
    public function rejectIdentityDocument(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $doc = IdentityDocument::findOrFail($id);
        $reason = $request->input('reason');

        $doc->update([
            'status' => 'rejected',
            'is_verified' => false,
            'rejection_reason' => $reason
        ]);

        // Mark user as unverified
        $user = $doc->user;
        $user->update(['is_verified' => false]);

        // Clear user profile cache
        Cache::forget("auth_user_profile_{$user->id}");

        $title = "Identity Document Rejected";
        $body = "Your identity document has been rejected. Reason: {$reason}";

        $this->notifyUser($user->id, $title, $body, [
            'type' => 'identity_verified',
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'document_id' => $doc->id
        ]);

        return response()->json([
            'message' => 'Identity document rejected.',
            'data' => $doc
        ]);
    }

    /**
     * Approve an apartment document.
     * Auto-publishes the apartment if all documents are approved.
     */
    public function verifyApartmentDocument(Request $request, $id)
    {
        $doc = ApartmentDocument::findOrFail($id);
        $apartment = $doc->apartment;

        $doc->update([
            'status' => 'approved',
            'rejection_reason' => null
        ]);

        // Check if all documents for this apartment are approved (specifically, the 1:1 document)
        $allApproved = true;
        $documents = ApartmentDocument::where('apartment_id', $apartment->id)->get();
        foreach ($documents as $d) {
            if ($d->status !== 'approved') {
                $allApproved = false;
                break;
            }
        }

        if ($allApproved) {
            $apartment->update([
                'status' => 'open',
                'verification_status' => 'approved'
            ]);
            
            // Invalidate caches
            Cache::forget("apartment_details_{$apartment->id}");
            Cache::forget("apartments_list_gender_male");
            Cache::forget("apartments_list_gender_female");
            Cache::forget("apartments_list_gender_any");
        }

        $title = "Apartment Document Approved";
        $body = "Your apartment document has been approved." . ($allApproved ? " Your apartment is now published." : "");

        $this->notifyUser($apartment->owner_id, $title, $body, [
            'type' => 'apartment_document_verified',
            'status' => 'approved',
            'apartment_id' => $apartment->id,
            'document_id' => $doc->id,
            'is_published' => $allApproved
        ]);

        return response()->json([
            'message' => 'Apartment document approved.' . ($allApproved ? ' Apartment is now published.' : ''),
            'data' => $doc
        ]);
    }

    /**
     * Reject an apartment document with a reason.
     */
    public function rejectApartmentDocument(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $doc = ApartmentDocument::findOrFail($id);
        $apartment = $doc->apartment;
        $reason = $request->input('reason');

        $doc->update([
            'status' => 'rejected',
            'rejection_reason' => $reason
        ]);

        // Mark apartment verification status as rejected
        $apartment->update([
            'verification_status' => 'rejected'
        ]);

        // Invalidate caches
        Cache::forget("apartment_details_{$apartment->id}");
        Cache::forget("apartments_list_gender_male");
        Cache::forget("apartments_list_gender_female");
        Cache::forget("apartments_list_gender_any");

        $title = "Apartment Document Rejected";
        $body = "Your apartment document has been rejected. Reason: {$reason}";

        $this->notifyUser($apartment->owner_id, $title, $body, [
            'type' => 'apartment_document_verified',
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'apartment_id' => $apartment->id,
            'document_id' => $doc->id
        ]);

        return response()->json([
            'message' => 'Apartment document rejected.',
            'data' => $doc
        ]);
    }

    /**
     * Approve a tenant's signed contract.
     * Triggers payment requirement flow when all members' contracts are approved.
     */
    public function verifyTenantContract(Request $request, $id)
    {
        $contract = TenantContract::findOrFail($id);

        $contract->update([
            'status' => 'accepted'
        ]);

        $title = "Contract Verified";
        $body = "Your signed contract has been verified and accepted.";

        $this->notifyUser($contract->user_id, $title, $body, [
            'type' => 'contract_verified',
            'status' => 'accepted',
            'apartment_id' => $contract->apartment_id,
            'contract_id' => $contract->id
        ]);

        // Check and trigger payment flow if all requirements met
        $this->checkAndTriggerPaymentFlow($contract->apartment_id);

        return response()->json([
            'message' => 'Tenant contract approved.',
            'data' => $contract
        ]);
    }

    /**
     * Reject a tenant's signed contract with a reason.
     */
    public function rejectTenantContract(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $contract = TenantContract::findOrFail($id);
        $reason = $request->input('reason');

        $contract->update([
            'status' => 'refused'
        ]);

        $title = "Contract Rejected";
        $body = "Your signed contract has been rejected. Reason: {$reason}. Please sign and upload the contract again.";

        $this->notifyUser($contract->user_id, $title, $body, [
            'type' => 'contract_verified',
            'status' => 'refused',
            'rejection_reason' => $reason,
            'apartment_id' => $contract->apartment_id,
            'contract_id' => $contract->id
        ]);

        return response()->json([
            'message' => 'Tenant contract rejected.',
            'data' => $contract
        ]);
    }

    /**
     * Get all moderation details for an apartment (owner identity, ownership documents, and all tenants' profile/contracts/IDs).
     */
    public function getApartmentModerationDetails(Request $request, $id)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            abort(403, 'Unauthorized action.');
        }

        // Find the apartment with basic relations
        $apartment = Apartment::with(['document', 'owner'])->findOrFail($id);

        // Find the owner's identity document
        $ownerIdentityDocument = IdentityDocument::where('user_id', $apartment->owner_id)->first();

        // Get the active/pending members
        $members = ApartmentMember::where('apartment_id', $apartment->id)
            ->whereIn('membership_status', ['pending', 'active'])
            ->get();

        $tenantsDetails = [];

        foreach ($members as $member) {
            $user = $member->user;
            if ($user) {
                // Find tenant's identity document
                $tenantIdentityDoc = IdentityDocument::where('user_id', $user->id)->first();

                // Find tenant's signed contract for this apartment
                $tenantContract = TenantContract::where('user_id', $user->id)
                    ->where('apartment_id', $apartment->id)
                    ->first();

                $profile = $user->profile;
                $tenantsDetails[] = [
                    'user_id'           => $user->id,
                    'name'              => trim(($user->profile?->first_name ?? '') . ' ' . ($user->profile?->last_name ?? '')),
                    'email'             => $user->email,
                    'phone'             => $user->phone,
                    'gender'            => $user->gender,
                    'is_verified'       => (bool) $user->is_verified,
                    'liveness_passed'   => (bool) $user->liveness_passed,
                    'face_match_passed' => (bool) $user->face_match_passed,
                    'ocr_data' => $profile ? [
                        'id_number'      => $profile->id_number,
                        'birth_date'     => $profile->birth_date,
                        'address'        => $profile->address,
                        'id_expiry_date' => $profile->id_expiry_date,
                        'id_issue_date'  => $profile->id_issue_date,
                        'profession'     => $profile->profession,
                        'religion'       => $profile->religion,
                        'marital_status' => $profile->marital_status,
                    ] : null,
                    'membership' => [
                        'id'               => $member->id,
                        'status'           => $member->membership_status,
                        'payment_deadline' => $member->payment_deadline,
                    ],
                    'identity_document' => $tenantIdentityDoc ? [
                        'id'              => $tenantIdentityDoc->id,
                        'type'            => $tenantIdentityDoc->type,
                        'document_number' => $tenantIdentityDoc->document_number,
                        'path'            => $tenantIdentityDoc->path,
                        'file_url'        => $tenantIdentityDoc->path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenantIdentityDoc->path) : null,
                        'status'          => $tenantIdentityDoc->status,
                        'is_verified'     => (bool) $tenantIdentityDoc->is_verified,
                    ] : null,
                    'signed_contract' => $tenantContract ? [
                        'id'       => $tenantContract->id,
                        'path'     => $tenantContract->path,
                        'file_url' => $tenantContract->path ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenantContract->path) : null,
                        'status'   => $tenantContract->status,
                    ] : null,
                ];
            }
        }

        // Load photos for this apartment
        $photos = \App\Models\ApartmentPhoto::where('apartment_id', $apartment->id)->get()
            ->map(fn($p) => [
                'id'       => $p->id,
                'path'     => $p->path,
                'file_url' => $p->path ? \Illuminate\Support\Facades\Storage::disk('public')->url($p->path) : null,
            ])->values()->toArray();

        return response()->json([
            'data' => [
                'apartment' => [
                    'id' => $apartment->id,
                    'title' => $apartment->title,
                    'location' => $apartment->location,
                    'owner_id' => $apartment->owner_id,
                    'price' => $apartment->price,
                    'insurance' => $apartment->insurance,
                    'capacity' => $apartment->capacity,
                    'rooms_count' => $apartment->rooms_count,
                    'beds_count' => $apartment->beds_count,
                    'gender_allowed' => $apartment->gender_allowed,
                    'status' => $apartment->status,
                    'verification_status' => $apartment->verification_status,
                    'photos' => $photos,
                ],
                'owner' => $apartment->owner ? [
                    'id' => $apartment->owner->id,
                    'name' => trim(($apartment->owner->profile?->first_name ?? '') . ' ' . ($apartment->owner->profile?->last_name ?? '')),
                    'email' => $apartment->owner->email,
                    'phone' => $apartment->owner->phone,
                    'is_verified' => (bool) $apartment->owner->is_verified,
                ] : null,
                'owner_identity_document' => $ownerIdentityDocument ? [
                    'id' => $ownerIdentityDocument->id,
                    'type' => $ownerIdentityDocument->type,
                    'document_number' => $ownerIdentityDocument->document_number,
                    'path' => $ownerIdentityDocument->path,
                    'file_url' => $ownerIdentityDocument->path ? \Illuminate\Support\Facades\Storage::disk('public')->url($ownerIdentityDocument->path) : null,
                    'status' => $ownerIdentityDocument->status,
                    'is_verified' => (bool) $ownerIdentityDocument->is_verified,
                ] : null,
                'ownership_document' => $apartment->document ? [
                    'id' => $apartment->document->id,
                    'document_type' => $apartment->document->document_type,
                    'path' => $apartment->document->path,
                    'file_url' => $apartment->document->path ? \Illuminate\Support\Facades\Storage::disk('public')->url($apartment->document->path) : null,
                    'status' => $apartment->document->status,
                ] : null,
                'tenants' => $tenantsDetails,
            ]
        ]);
    }

    /**
     * POST /api/admin/apartments/{id}/retrigger-payment
     * Manually retrigger the payment flow (bypasses duplicate guard for this call only).
     */
    public function retriggerPayment(Request $request, $id)
    {
        if (!$request->user()->isAdmin()) abort(403);

        // Remove the duplicate guard by deleting any stale pending orders first
        \App\Models\PaymentOrder::where('apartment_id', $id)
            ->where('status', 'pending')
            ->delete();

        $this->checkAndTriggerPaymentFlow($id);

        return response()->json(['message' => 'Payment flow retriggered.']);
    }

    /**
     * Check if all contracts and documents are verified, then trigger payment flow.
     */
    public function checkAndTriggerPaymentFlow($apartmentId)
    {
        $apartment = Apartment::findOrFail($apartmentId);
        
        // Find all pending/active members of the apartment
        $members = ApartmentMember::where('apartment_id', $apartmentId)
            ->whereIn('membership_status', ['pending', 'active'])
            ->get();

        if ($members->isEmpty()) {
            return;
        }

        $memberUserIds = $members->pluck('user_id')->toArray();
        $N = count($memberUserIds);

        // Check if all members have accepted contracts
        $acceptedContractsCount = TenantContract::where('apartment_id', $apartmentId)
            ->whereIn('user_id', $memberUserIds)
            ->where('status', 'accepted')
            ->count();

        // Check if all members are identity-verified (use users.is_verified as the authoritative flag)
        $verifiedUsersCount = \App\Models\User::whereIn('id', $memberUserIds)
            ->where('is_verified', true)
            ->count();

        // Skip if payment orders already exist for this apartment (avoid duplicates)
        $existingOrdersCount = \App\Models\PaymentOrder::where('apartment_id', $apartmentId)
            ->whereIn('status', ['pending', 'paid'])
            ->count();

        if ($acceptedContractsCount === $N && $verifiedUsersCount === $N && $existingOrdersCount === 0) {
            $deadline = now()->addDays(5);

            // Update payment deadline for all members
            ApartmentMember::where('apartment_id', $apartmentId)
                ->whereIn('membership_status', ['pending', 'active'])
                ->update([
                    'payment_deadline' => $deadline
                ]);

            \Illuminate\Support\Facades\DB::transaction(function () use ($apartment, $members, $N, $deadline) {
                // Lock apartment row
                $lockedApartment = Apartment::lockForUpdate()->find($apartment->id);

                // Find cycle number
                $latestCycle = RentCycle::where('apartment_id', $lockedApartment->id)
                    ->orderBy('cycle_number', 'desc')
                    ->first();
                $cycleNumber = $latestCycle ? $latestCycle->cycle_number + 1 : 1;

                // Create RentCycle record
                $rentCycle = RentCycle::create([
                    'apartment_id' => $lockedApartment->id,
                    'cycle_number' => $cycleNumber,
                    'starts_at' => now(),
                    'ends_at' => $deadline,
                    'status' => 'pending_payment'
                ]);

                $paymobService = resolve(PaymobService::class);
                $paymobToken = $paymobService->getAuthToken();

                foreach ($members as $member) {
                    $user = $member->user;
                    $idempotencyKey = 'pay_order_' . $rentCycle->id . '_' . $user->id;

                    // Check if they already paid insurance specifically for this apartment
                    $hasPaidInsurance = InsurancePayment::where('user_id', $user->id)
                        ->where('apartment_id', $lockedApartment->id)
                        ->exists();

                    // Calculations — rent share only; 50% admin commission is taken at payout time
                    $rentShareEgp = ceil($lockedApartment->price / $N);
                    $insuranceShareEgp = $hasPaidInsurance ? 0 : ceil($lockedApartment->insurance / $N);

                    $rentShareCents = (int) ($rentShareEgp * 100);
                    $insuranceShareCents = (int) ($insuranceShareEgp * 100);

                    $totalAmountCents = $rentShareCents + $insuranceShareCents;

                    $paymobOrderId = null;
                    $paymobPaymentKey = null;
                    $paymentUrl = null;

                    if ($paymobToken) {
                        $paymobOrderId = $paymobService->createOrder($paymobToken, $totalAmountCents, $idempotencyKey);
                        if ($paymobOrderId) {
                            $billingData = [
                                'first_name' => $user->profile?->first_name ?? 'Tenant',
                                'last_name' => $user->profile?->last_name ?? 'User',
                                'email' => $user->email ?? 'tenant@example.com',
                                'phone_number' => $user->phone ?? '01000000000',
                            ];
                            $paymobPaymentKey = $paymobService->createPaymentKey($paymobToken, $paymobOrderId, $totalAmountCents, $billingData);
                            if ($paymobPaymentKey) {
                                $paymentUrl = $paymobService->getPaymentUrl($paymobPaymentKey);
                            }
                        }
                    }

                    PaymentOrder::create([
                        'idempotency_key' => $idempotencyKey,
                        'rent_cycle_id' => $rentCycle->id,
                        'apartment_id' => $lockedApartment->id,
                        'user_id' => $user->id,
                        'amount_cents' => $totalAmountCents,
                        'breakdown' => [
                            'rent_cents' => $rentShareCents,
                            'insurance_cents' => $insuranceShareCents,
                            'platform_fee_cents' => 0,
                        ],
                        'paymob_order_id' => $paymobOrderId !== null ? (string) $paymobOrderId : null,
                        'paymob_payment_key' => $paymobPaymentKey,
                        'payment_url' => $paymentUrl,
                        'status' => 'pending',
                        'expires_at' => $deadline,
                    ]);

                    $payTitle = "Contracts Verified - Payment Required";
                    $payBody = "Your contracts and documents are verified. Please pay your share within 5 days or you will be removed from the apartment.";
                    
                    $this->notifyUser($user->id, $payTitle, $payBody, [
                        'type' => 'payment_required',
                        'deadline' => $deadline->toIso8601String(),
                        'apartment_id' => $lockedApartment->id,
                        'payment_link' => $paymentUrl ?? '',
                        'amount_egp' => $totalAmountCents / 100,
                    ]);
                }
            });
        }
    }

    /** POST /api/admin/apartments/{id}/verify */
    public function verifyApartment(Request $request, $id)
    {
        if (!$request->user()->isAdmin()) abort(403);
        $apartment = Apartment::findOrFail($id);
        $apartment->update(['verification_status' => 'approved', 'status' => 'open']);
        Cache::forget("apartment_details_{$apartment->id}");
        Cache::forget("apartments_list_gender_male");
        Cache::forget("apartments_list_gender_female");
        Cache::forget("apartments_list_gender_any");
        $this->notifyUser($apartment->owner_id, 'Apartment Approved', 'Your apartment listing is now live.', [
            'type' => 'apartment_approved', 'apartment_id' => $apartment->id,
        ]);
        return response()->json(['message' => 'Apartment approved.', 'data' => $apartment]);
    }

    /** POST /api/admin/apartments/{id}/refuse */
    public function refuseApartment(Request $request, $id)
    {
        if (!$request->user()->isAdmin()) abort(403);
        $apartment = Apartment::findOrFail($id);
        $apartment->update(['verification_status' => 'rejected', 'status' => 'draft']);
        Cache::forget("apartment_details_{$apartment->id}");
        Cache::forget("apartments_list_gender_male");
        Cache::forget("apartments_list_gender_female");
        Cache::forget("apartments_list_gender_any");
        $reason = $request->input('reason', 'No reason provided.');
        $this->notifyUser($apartment->owner_id, 'Apartment Refused', "Your apartment was refused. Reason: $reason", [
            'type' => 'apartment_refused', 'apartment_id' => $apartment->id, 'reason' => $reason,
        ]);
        return response()->json(['message' => 'Apartment refused.', 'data' => $apartment]);
    }

    /** DELETE /api/admin/identity-documents/{id} */
    public function deleteIdentityDocument(Request $request, $id)
    {
        if (!$request->user()->isAdmin()) abort(403);
        $doc = IdentityDocument::findOrFail($id);
        $userId = $doc->user_id;
        $doc->delete();
        Cache::forget("auth_user_profile_{$userId}");
        return response()->json(['message' => 'Identity document deleted.']);
    }

    /** DELETE /api/admin/apartment-documents/{id} */
    public function deleteApartmentDocument(Request $request, $id)
    {
        if (!$request->user()->isAdmin()) abort(403);
        // Idempotent delete: if it's already gone, return 200 so clients can retry safely.
        $doc = ApartmentDocument::find($id);
        if (!$doc) {
            return response()->json(['message' => 'Apartment document already deleted.']);
        }
        $doc->delete();
        return response()->json(['message' => 'Apartment document deleted.']);
    }

    /** DELETE /api/admin/tenant-contracts/{id} */
    public function deleteTenantContract(Request $request, $id)
    {
        if (!$request->user()->isAdmin()) abort(403);
        $contract = TenantContract::findOrFail($id);
        $userId = $contract->user_id;
        $apartmentId = $contract->apartment_id;

        $contract->delete();

        // If the member is still pending, remove their membership too
        ApartmentMember::where('user_id', $userId)
            ->where('apartment_id', $apartmentId)
            ->where('membership_status', 'pending')
            ->delete();

        return response()->json(['message' => 'Tenant contract deleted.']);
    }

    /**
     * Pending ID verification submissions (ML + stored front/back images).
     * GET /api/admin/identity-verifications/pending
     */
    public function getPendingIdentityVerifications(Request $request)
    {
        if (!$request->user()->isAdmin()) {
            abort(403);
        }

        $rows = IdentityVerification::with(['user.profile', 'user.roles'])
            ->where(function ($q) {
                $q->where('admin_review_status', 'pending')
                    ->orWhere(function ($q2) {
                        $q2->whereNull('admin_review_status')
                            ->whereIn('overall_status', ['failed', 'pending', 'processing']);
                    });
            })
            ->whereHas('user', function ($u) use ($request) {
                $u->where('is_verified', false);
                if ($request->filled('role')) {
                    $role = $request->input('role');
                    $u->whereHas('roles', fn ($r) => $r->where('role', $role));
                }
            })
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . $request->input('q') . '%';
                $q->where(function ($w) use ($term) {
                    $w->where('extracted_name', 'like', $term)
                        ->orWhere('id_number', 'like', $term)
                        ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $term));
                });
            })
            ->orderByDesc('submitted_at');

        $perPage = min((int) $request->input('per_page', 50), 100);
        $paginator = $rows->paginate($perPage);

        $data = $paginator->getCollection()->map(function (IdentityVerification $v) {
            $primaryRole = $v->user->roles->first()?->role ?? 'rental';
            return [
                'id' => $v->id,
                'user_id' => $v->user_id,
                'user' => [
                    'id' => $v->user->id,
                    'email' => $v->user->email,
                    'name' => $v->user->profile?->name ?? $v->user->name,
                    'role' => $primaryRole,
                ],
                'id_number' => $v->id_number,
                'extracted_name' => $v->extracted_name,
                'overall_status' => $v->overall_status,
                'admin_review_status' => $v->admin_review_status,
                'front_image_url' => $v->front_image_path
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($v->front_image_path)
                    : null,
                'back_image_url' => $v->back_image_path
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($v->back_image_path)
                    : null,
                'selfie_image_url' => $v->selfie_image_path
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($v->selfie_image_path)
                    : null,
                'submitted_at' => $v->submitted_at,
            ];
        });

        return response()->json([
            'data' => $data,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Approve or reject an identity verification submission.
     * POST /api/admin/identity-verifications/{id}/review
     */
    public function reviewIdentityVerification(Request $request, string $id)
    {
        if (!$request->user()->isAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:action,reject|string|max:1000',
        ]);

        $verification = IdentityVerification::with('user')->findOrFail($id);
        $user = $verification->user;

        if ($data['action'] === 'approve') {
            $verification->update([
                'admin_review_status' => 'approved',
                'overall_status' => 'completed',
                'completed_at' => now(),
            ]);
            $user->update([
                'is_verified' => true,
                'liveness_passed' => true,
                'face_match_passed' => true,
            ]);
            $this->notifyUser($user->id, 'Identity Approved', 'Your ID has been approved. You can use Sukoon fully now.', [
                'type' => 'identity_verified',
            ]);
        } else {
            $reason = $data['rejection_reason'];
            $verification->update([
                'admin_review_status' => 'rejected',
                'overall_status' => 'failed',
            ]);
            $user->update(['is_verified' => false]);
            $this->notifyUser($user->id, 'Identity Rejected', "Your ID was rejected: {$reason}", [
                'type' => 'identity_rejected',
            ]);
        }

        Cache::forget("auth_user_profile_{$user->id}");

        return response()->json(['message' => 'Review saved', 'data' => $verification->fresh()]);
    }

    /**
     * Get pending identity documents for admin review.
     * GET /api/admin/identity-documents/pending
     */
    public function getPendingIdentityDocuments(Request $request)
    {
        if (!$request->user()->isAdmin()) abort(403);

        $docs = IdentityDocument::with(['user', 'user.profile'])
            ->where('status', 'pending')
            ->orWhereNull('status')
            ->orderByDesc('created_at')
            ->get();

        $data = $docs->map(function ($doc) {
            return [
                'id' => $doc->id,
                'user_id' => $doc->user_id,
                'user' => [
                    'id' => $doc->user->id,
                    'email' => $doc->user->email,
                    'name' => $doc->user->name,
                    'profile' => $doc->user->profile,
                ],
                'type' => $doc->type,
                'document_number' => $doc->document_number,
                'path' => $doc->path,
                'file_url' => $doc->path ? \Illuminate\Support\Facades\Storage::disk('public')->url($doc->path) : null,
                'status' => $doc->status ?? 'pending',
                'is_verified' => $doc->is_verified,
                'created_at' => $doc->created_at,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Admin reviews ID and enters extracted data.
     * POST /api/admin/identity-documents/{id}/review
     */
    public function reviewIdentityDocument(Request $request, $id)
    {
        if (!$request->user()->isAdmin()) abort(403);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'id_number' => 'required|string|max:50',
            'birth_date' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'gender' => 'nullable|string|in:male,female',
            'profession' => 'nullable|string|max:100',
            'religion' => 'nullable|string|max:50',
            'marital_status' => 'nullable|string|max:50',
            'action' => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:action,reject|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $doc = IdentityDocument::findOrFail($id);
        $user = $doc->user;
        $action = $request->input('action');

        if ($action === 'approve') {
            // Update document status
            $doc->update([
                'status' => 'approved',
                'is_verified' => true,
                'document_number' => $request->input('id_number'),
            ]);

            // Update user verification status
            $user->update([
                'is_verified' => true,
                'liveness_passed' => true,
                'face_match_passed' => true,
                'gender' => $request->input('gender') ?? $user->gender,
            ]);

            // Update user profile with extracted data
            $profileData = array_filter([
                'name' => $request->input('name'),
                'id_number' => $request->input('id_number'),
                'birth_date' => $request->input('birth_date'),
                'address' => $request->input('address'),
                'profession' => $request->input('profession'),
                'religion' => $request->input('religion'),
                'marital_status' => $request->input('marital_status'),
            ]);

            if (!empty($profileData)) {
                if (!$user->profile) {
                    $user->profile()->create($profileData);
                } else {
                    $user->profile->fill($profileData)->save();
                }
            }

            // Clear cache
            Cache::forget("auth_user_profile_{$user->id}");

            // Notify user
            $title = "Identity Verified ✓";
            $body = "Your ID has been verified and your profile has been updated with the extracted information.";
            $this->notifyUser($user->id, $title, $body, [
                'type' => 'identity_verified',
                'status' => 'approved',
            ]);

            $pendingMembership = ApartmentMember::where('user_id', $user->id)
                ->where('membership_status', 'pending')
                ->first();
            if ($pendingMembership) {
                $this->checkAndTriggerPaymentFlow($pendingMembership->apartment_id);
            }

            return response()->json([
                'message' => 'Identity document approved and user profile updated.',
                'data' => $doc->fresh(['user', 'user.profile'])
            ]);
        } else {
            // Reject
            $doc->update([
                'status' => 'rejected',
                'is_verified' => false,
                'rejection_reason' => $request->input('rejection_reason'),
            ]);

            $user->update(['is_verified' => false]);
            Cache::forget("auth_user_profile_{$user->id}");

            $reason = $request->input('rejection_reason');
            $title = "Identity Verification Rejected";
            $body = "Your ID document was rejected. Reason: {$reason}";
            $this->notifyUser($user->id, $title, $body, [
                'type' => 'identity_rejected',
                'status' => 'rejected',
                'reason' => $reason,
            ]);

            return response()->json([
                'message' => 'Identity document rejected.',
                'data' => $doc
            ]);
        }
    }
}
