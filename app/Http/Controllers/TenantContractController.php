<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\ApartmentMember;
use App\Models\PaymentOrder;
use App\Models\TenantContract;
use App\Models\Notification;
use App\Models\User;
use App\Jobs\SendNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class TenantContractController extends Controller
{
    /**
     * GET /api/tenant/membership — returns current membership + contract status for the tenant.
     */
    public function myMembership(Request $request)
    {
        $user = $request->user();

        $membership = ApartmentMember::where('user_id', $user->id)
            ->whereIn('membership_status', ['pending', 'active'])
            ->with(['apartment.photos'])
            ->first();

        if (!$membership) {
            return response()->json(['data' => null]);
        }

        $contract = TenantContract::where('user_id', $user->id)
            ->where('apartment_id', $membership->apartment_id)
            ->orderByDesc('created_at')
            ->first();

        $paymentOrder = PaymentOrder::where('user_id', $user->id)
            ->where('apartment_id', $membership->apartment_id)
            ->whereIn('status', ['pending', 'unpaid'])
            ->orderByDesc('created_at')
            ->first();

        // Include owner contact + co-tenants only when membership is active (rent cycle started)
        $owner     = null;
        $coTenants = [];

        if ($membership->membership_status === 'active') {
            $apartment = Apartment::with('owner.profile')->find($membership->apartment_id);
            if ($apartment) {
                $ownerUser = $apartment->owner;
                $owner = [
                    'name'  => trim(($ownerUser->profile?->first_name ?? '') . ' ' . ($ownerUser->profile?->last_name ?? '')) ?: $ownerUser->name ?? 'Owner',
                    'phone' => $ownerUser->phone ?? null,
                    'email' => $ownerUser->email ?? null,
                ];

                $coTenants = ApartmentMember::where('apartment_id', $membership->apartment_id)
                    ->where('user_id', '!=', $user->id)
                    ->where('membership_status', 'active')
                    ->with(['user.profile'])
                    ->get()
                    ->map(function ($m) use ($membership) {
                        $hasPaid = PaymentOrder::where('user_id', $m->user_id)
                            ->where('apartment_id', $membership->apartment_id)
                            ->where('status', 'paid')
                            ->exists();
                        return [
                            'name'     => trim(($m->user->profile?->first_name ?? '') . ' ' . ($m->user->profile?->last_name ?? '')) ?: $m->user->name ?? 'Tenant',
                            'phone'    => $m->user->phone ?? null,
                            'has_paid' => $hasPaid,
                        ];
                    })
                    ->values()
                    ->toArray();
            }
        }

        return response()->json([
            'data' => [
                'membership'    => $membership,
                'apartment'     => $membership->apartment,
                'contract'      => $contract,
                'payment_order' => $paymentOrder,
                'owner'         => $owner,
                'co_tenants'    => $coTenants,
            ]
        ]);
    }

    /**
     * Upload signed contract for a tenant's apartment.
     */
    public function uploadContract(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:pdf,jpeg,png,jpg|max:10240', // 10MB limit
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $request->user();

        // 1. Check if the user is a member of any apartment (pending or active)
        $membership = ApartmentMember::where('user_id', $user->id)
            ->whereIn('membership_status', ['pending', 'active'])
            ->first();

        if (!$membership) {
            return response()->json([
                'message' => 'You do not have a pending or active membership in any apartment.',
                'error_code' => 'NOT_MEMBER'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $apartmentId = $membership->apartment_id;

        // Fix 6: Validate that the sent apartment_id matches the user's actual membership
        $sentApartmentId = $request->input('apartment_id');
        if ($sentApartmentId && (string)$sentApartmentId !== (string)$apartmentId) {
            return response()->json([
                'message' => 'The apartment_id does not match your current membership.',
                'error_code' => 'APARTMENT_MISMATCH'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 2. Enforce only one active contract per tenant per apartment
        $existingContract = TenantContract::where('user_id', $user->id)
            ->where('apartment_id', $apartmentId)
            ->first();

        if ($existingContract) {
            // Delete old file
            if (Storage::disk('public')->exists($existingContract->path)) {
                Storage::disk('public')->delete($existingContract->path);
            }
            $existingContract->delete();
        }

        // Store new file with binary data
        $file = $request->file('file');
        $filePath = $file->store('tenants_contracts', 'public');

        // Create the contract record
        $contract = TenantContract::create([
            'user_id' => $user->id,
            'apartment_id' => $apartmentId,
            'path' => $filePath,
            'type' => 'signed_contract',
            'status' => 'pending',
            'move_in_date' => $request->input('move_in_date'),
            'lease_duration' => $request->input('lease_duration'),
            'occupants' => $request->input('occupants'),
            'message' => $request->input('message'),
        ]);

        // Store binary data in database
        $contract->storeFileData($file);

        // 3. Check if all members of the apartment have uploaded their contracts
        $memberUserIds = ApartmentMember::where('apartment_id', $apartmentId)
            ->whereIn('membership_status', ['pending', 'active'])
            ->pluck('user_id')
            ->toArray();

        $uploadedContractsCount = TenantContract::where('apartment_id', $apartmentId)
            ->whereIn('user_id', $memberUserIds)
            ->whereIn('status', ['pending', 'accepted'])
            ->count();

        if ($uploadedContractsCount === count($memberUserIds)) {
            // Notify admins
            $admins = User::whereHas('roles', function ($query) {
                $query->where('role', 'admin');
            })->get();

            if ($admins->isNotEmpty()) {
                $adminIds = $admins->pluck('id')->toArray();
                $title = "Apartment Contracts Ready for Review";
                $body = "All tenants of Apartment " . $apartmentId . " have uploaded their contracts. Please review and verify them.";
                $data = [
                    'type' => 'apartment_contracts_ready',
                    'apartment_id' => $apartmentId,
                ];

                // Create persistent notifications for admins
                foreach ($adminIds as $adminId) {
                    Notification::updateOrCreate([
                        'user_id' => $adminId,
                        'dedupe_key' => 'apt_contracts_ready_' . $apartmentId . '_' . $adminId,
                    ], [
                        'type' => 'apartment_contracts_ready',
                        'data' => array_merge(['title' => $title, 'body' => $body], $data),
                        'status' => 'pending',
                    ]);
                }

                // Dispatch FCM job
                SendNotification::dispatch($adminIds, $title, $body, $data, ['fcm']);
            }
        }

        return response()->json([
            'message' => 'Contract uploaded successfully and is pending review.',
            'data' => $contract
        ], Response::HTTP_CREATED);
    }
}
