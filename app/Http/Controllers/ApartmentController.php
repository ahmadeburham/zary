<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\ApartmentPhoto;
use App\Models\ApartmentDocument;
use App\Models\ApartmentMember;
use App\Events\ApartmentCapacityIsFull;
use App\Models\RentCycle;
use App\Models\PaymentOrder;
use App\Models\InsurancePayment;
use App\Models\Notification;
use App\Jobs\SendNotification;
use App\Services\DataIntegrityService;
use App\Services\PaymobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class ApartmentController extends Controller
{
    public function __construct(
        protected DataIntegrityService $dataIntegrity,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Apartment::class);

        $user = $request->user();
        $perPage = min($request->input('per_page', 50), 100);
        $page = $request->input('page', 1);

        if ($user->isAdmin()) {
            // Admins can see everything with pagination
            $apartments = Apartment::withLatLng()
                ->with(['photos', 'document', 'owner.profile'])
                ->withCount(['members as active_members_count' => fn($q) => $q->whereNotIn('membership_status', ['cancelled'])])
                ->orderByDesc('created_at')
                ->paginate($perPage);
            
            return response()->json([
                'data' => $apartments->items(),
                'pagination' => [
                    'current_page' => $apartments->currentPage(),
                    'per_page' => $apartments->perPage(),
                    'total' => $apartments->total(),
                    'last_page' => $apartments->lastPage(),
                ]
            ]);
        } elseif ($user->isOwner()) {
            // Owners see their own apartments with pagination
            $apartments = Apartment::withLatLng()
                ->with(['photos', 'document', 'owner.profile'])
                ->where('owner_id', $user->id)
                ->orderByDesc('created_at')
                ->paginate($perPage);
            
            return response()->json([
                'data' => $apartments->items(),
                'pagination' => [
                    'current_page' => $apartments->currentPage(),
                    'per_page' => $apartments->perPage(),
                    'total' => $apartments->total(),
                    'last_page' => $apartments->lastPage(),
                ]
            ]);
        } else {
            // Tenants (Rental) see approved & open apartments matching their gender
            $gender = $user->gender;
            $cacheKey = "apartments_list_gender_{$gender}_page_{$page}_per_page_{$perPage}";
            
            $result = Cache::remember($cacheKey, 3600, function () use ($gender, $perPage) {
                $apartments = Apartment::withLatLng()
                    ->with(['photos', 'document', 'owner.profile'])
                    ->where('status', 'open')
                    ->where('verification_status', 'approved')
                    ->whereIn('gender_allowed', [$gender, 'any'])
                    ->orderByDesc('created_at')
                    ->paginate($perPage);
                
                return [
                    'data' => $apartments->items(),
                    'pagination' => [
                        'current_page' => $apartments->currentPage(),
                        'per_page' => $apartments->perPage(),
                        'total' => $apartments->total(),
                        'last_page' => $apartments->lastPage(),
                    ]
                ];
            });
            
            return response()->json($result);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Apartment::class);

        $validator = Validator::make($request->all(), [
            'price' => 'required|numeric|min:0',
            'insurance' => 'required|numeric|min:0',
            'capacity' => 'required|integer|min:1',
            'gender_allowed' => 'required|in:male,female,any',
            'rooms_count' => 'required|integer|min:1',
            'beds_count' => 'required|integer|min:1',
            'has_ac' => 'boolean',
            'has_water' => 'boolean',
            'has_gas' => 'boolean',
            'is_furnished' => 'boolean',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'location_label' => 'nullable|string|max:255',
            'rent_duration' => 'integer|min:1',
            'photos' => 'array',
            'photos.*' => 'file|image|mimes:jpeg,png,jpg|max:5120', // 5MB max
            'document' => 'file|mimes:pdf,jpeg,png,jpg|max:10240', // 10MB max
            'document_type' => 'required_with:document|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Create the draft apartment
        $apartmentData = $request->except(['photos', 'document', 'document_type', 'male_count', 'female_count']);
        $apartmentData['owner_id'] = $request->user()->id;
        $apartmentData['status'] = 'draft';
        $apartmentData['verification_status'] = 'pending';
        $apartmentData['latitude'] = (float)$request->input('latitude');
        $apartmentData['longitude'] = (float)$request->input('longitude');
        $apartmentData['location_label'] = $request->input('location_label', '');

        $apartment = Apartment::create($apartmentData);

        // Upload photos with binary data
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photoFile) {
                $photoPath = $photoFile->store('apartment_photos', 'public');
                $photo = ApartmentPhoto::create([
                    'apartment_id' => $apartment->id,
                    'path' => $photoPath,
                ]);
                // Store binary data in database
                $photo->storeFileData($photoFile);
            }
        }

        // Upload apartment document/contract with binary data (1:1 constraint)
        if ($request->hasFile('document')) {
            $docFile = $request->file('document');
            $docPath = $docFile->store('apartment_documents', 'public');
            $document = ApartmentDocument::create([
                'apartment_id' => $apartment->id,
                'user_id' => $request->user()->id,
                'path' => $docPath,
                'document_type' => $request->input('document_type'),
                'status' => 'pending',
                'rejection_reason' => null,
            ]);
            // Store binary data in database
            $document->storeFileData($docFile);
        }

        // Upload optional lease template PDF
        if ($request->hasFile('lease_template')) {
            $templatePath = $request->file('lease_template')->store('lease_templates', 'public');
            $apartment->update(['lease_template_path' => $templatePath]);
        }

        // Clear general list caches
        $this->clearApartmentCaches();

        // Notify all admins about the new listing
        $adminRole = \App\Models\Role::where('role', 'admin')->first();
        if ($adminRole) {
            $adminUsers = $adminRole->users()->with('profile')->get();
            foreach ($adminUsers as $admin) {
                $title = 'New Apartment Listing Submitted';
                $body  = "A new apartment listing has been submitted and is awaiting your review.";
                Notification::create([
                    'user_id'    => $admin->id,
                    'type'       => 'new_apartment_listing',
                    'dedupe_key' => 'new_apt_' . $apartment->id,
                    'data'       => [
                        'title'        => $title,
                        'body'         => $body,
                        'apartment_id' => $apartment->id,
                    ],
                    'status' => 'pending',
                ]);
                SendNotification::dispatch($admin->id, $title, $body, [
                    'type'         => 'new_apartment_listing',
                    'apartment_id' => $apartment->id,
                ], ['fcm']);
            }
        }

        $freshApartment = Apartment::withLatLng()
            ->with(['photos', 'document'])
            ->findOrFail($apartment->id);

        return response()->json([
            'message' => 'Apartment created successfully as draft and is pending verification.',
            'data' => $freshApartment
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $apartment = Cache::remember("apartment_details_{$id}", 3600, function () use ($id) {
            return Apartment::withLatLng()
                ->with(['photos', 'document', 'owner.profile'])
                ->findOrFail($id);
        });

        Gate::authorize('view', $apartment);

        return response()->json(['data' => $apartment]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $apartment = Apartment::findOrFail($id);

        Gate::authorize('update', $apartment);

        $validator = Validator::make($request->all(), [
            'price' => 'numeric|min:0',
            'insurance' => 'numeric|min:0',
            'capacity' => 'integer|min:1',
            'gender_allowed' => 'in:male,female,any',
            'rooms_count' => 'integer|min:1',
            'beds_count' => 'integer|min:1',
            'has_ac' => 'boolean',
            'has_water' => 'boolean',
            'has_gas' => 'boolean',
            'is_furnished' => 'boolean',
            'latitude' => 'numeric|between:-90,90',
            'longitude' => 'numeric|between:-180,180',
            'location_label' => 'nullable|string|max:255',
            'rent_duration' => 'integer|min:1',
            'photos' => 'array',
            'photos.*' => 'file|image|mimes:jpeg,png,jpg|max:5120',
            'delete_photos' => 'array',
            'delete_photos.*' => 'uuid',
            'document' => 'file|mimes:pdf,jpeg,png,jpg|max:10240',
            'document_type' => 'required_with:document|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Update basic fields
        $apartmentData = $request->except(['photos', 'delete_photos', 'document', 'document_type']);
        if ($request->has('latitude')) {
            $apartmentData['latitude'] = (float)$request->input('latitude');
        }
        if ($request->has('longitude')) {
            $apartmentData['longitude'] = (float)$request->input('longitude');
        }
        $apartment->fill($apartmentData);

        // Handle photo deletions
        if ($request->has('delete_photos')) {
            $photosToDelete = ApartmentPhoto::whereIn('id', $request->input('delete_photos'))
                ->where('apartment_id', $apartment->id)
                ->get();
            
            foreach ($photosToDelete as $p) {
                if (Storage::disk('public')->exists($p->path)) {
                    Storage::disk('public')->delete($p->path);
                }
                $p->delete();
            }
        }

        // Upload new photos
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photoFile) {
                $photoPath = $photoFile->store('apartment_photos', 'public');
                ApartmentPhoto::create([
                    'apartment_id' => $apartment->id,
                    'path' => $photoPath,
                ]);
            }
        }

        // Upload new apartment document (1:1 constraint)
        if ($request->hasFile('document')) {
            $existingDoc = ApartmentDocument::where('apartment_id', $apartment->id)->first();
            if ($existingDoc) {
                if (Storage::disk('public')->exists($existingDoc->path)) {
                    Storage::disk('public')->delete($existingDoc->path);
                }
                $existingDoc->delete();
            }

            $docPath = $request->file('document')->store('apartment_documents', 'public');
            ApartmentDocument::create([
                'apartment_id' => $apartment->id,
                'user_id' => $request->user()->id,
                'path' => $docPath,
                'document_type' => $request->input('document_type'),
                'status' => 'pending',
                'rejection_reason' => null,
            ]);

            // Re-uploading a contract resets verification status
            $apartment->status = 'draft';
            $apartment->verification_status = 'pending';
        }

        // Upload or replace lease template PDF
        if ($request->hasFile('lease_template')) {
            if ($apartment->lease_template_path && Storage::disk('public')->exists($apartment->lease_template_path)) {
                Storage::disk('public')->delete($apartment->lease_template_path);
            }
            $templatePath = $request->file('lease_template')->store('lease_templates', 'public');
            $apartment->lease_template_path = $templatePath;
        }

        $apartment->save();

        // Invalidate caches
        $this->clearApartmentCaches($apartment->id);

        $freshApartment = Apartment::withLatLng()
            ->with(['photos', 'document'])
            ->findOrFail($apartment->id);

        return response()->json([
            'message' => 'Apartment updated successfully.',
            'data' => $freshApartment
        ]);
    }

    /**
     * GET /api/apartments/{id}/lease-template
     * Returns a signed URL for the lease template PDF.
     * Accessible to: owner, active members, admins.
     */
    public function leaseTemplate(Request $request, string $id)
    {
        $user      = $request->user();
        $apartment = Apartment::findOrFail($id);

        $isOwner  = $apartment->owner_id === $user->id;
        $isAdmin  = $user->isAdmin();
        $isMember = $apartment->members()->where('user_id', $user->id)
            ->whereIn('membership_status', ['pending', 'active'])
            ->exists();

        if (!$isOwner && !$isAdmin && !$isMember) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (!$apartment->lease_template_path) {
            return response()->json(['message' => 'No lease template uploaded for this apartment.'], 404);
        }

        $url = Storage::disk('public')->url($apartment->lease_template_path);
        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $apartment = Apartment::findOrFail($id);

        Gate::authorize('delete', $apartment);

        // Delete photos
        $photos = ApartmentPhoto::where('apartment_id', $apartment->id)->get();
        foreach ($photos as $p) {
            if (Storage::disk('public')->exists($p->path)) {
                Storage::disk('public')->delete($p->path);
            }
            $p->delete();
        }

        // Delete documents
        $docs = ApartmentDocument::where('apartment_id', $apartment->id)->get();
        foreach ($docs as $d) {
            if (Storage::disk('public')->exists($d->path)) {
                Storage::disk('public')->delete($d->path);
            }
            $d->delete();
        }

        // Reset all member states before deleting
        $members = ApartmentMember::where('apartment_id', $apartment->id)
            ->with('user')
            ->get();

        $affectedUserIds = $members->pluck('user_id')->toArray();

        // Cancel pending/unpaid payment orders for all members
        PaymentOrder::where('apartment_id', $apartment->id)
            ->whereIn('status', ['pending', 'unpaid'])
            ->update(['status' => 'cancelled']);

        // Cancel active rent cycles
        RentCycle::where('apartment_id', $apartment->id)
            ->whereIn('status', ['active', 'pending'])
            ->update(['status' => 'cancelled']);

        // Remove all membership records
        ApartmentMember::where('apartment_id', $apartment->id)->delete();

        // Notify each affected user
        foreach ($members as $member) {
            Notification::create([
                'user_id'    => $member->user_id,
                'type'       => 'apartment_deleted',
                'dedupe_key' => 'apt_deleted_' . $apartment->id . '_' . $member->user_id,
                'data'       => [
                    'title'        => 'Apartment Removed',
                    'body'         => 'The apartment you were a member of has been deleted. Your membership and any pending payments have been cancelled.',
                    'type'         => 'apartment_deleted',
                    'apartment_id' => $apartment->id,
                ],
                'status' => 'pending',
            ]);
        }

        if (!empty($affectedUserIds)) {
            SendNotification::dispatch(
                $affectedUserIds,
                'Apartment Removed',
                'The apartment you were a member of has been deleted. Your membership and any pending payments have been cancelled.',
                ['type' => 'apartment_deleted', 'apartment_id' => $apartment->id],
                ['fcm']
            );
        }

        $this->dataIntegrity->onApartmentDeleted($apartment);
        $apartment->delete();

        // Invalidate caches
        $this->clearApartmentCaches($id);

        return response()->json([
            'message' => 'Apartment and all associated files deleted successfully.'
        ]);
    }

    /**
     * Join an apartment.
     */
    public function join(Request $request, string $id)
    {
        $user = $request->user();

        // 1. Retrieve the apartment
        $apartment = Apartment::findOrFail($id);

        // 2. Authorize via policy
        Gate::authorize('join', $apartment);

        $capacityReached = false;

        // 3. Perform inside transaction with row lock
        $result = DB::transaction(function () use ($apartment, $user, &$capacityReached) {
            // Lock the apartment row for concurrency safety
            $lockedApartment = Apartment::lockForUpdate()->findOrFail($apartment->id);

            // Check if the apartment is open
            if ($lockedApartment->status !== 'open') {
                return response()->json([
                    'message' => 'This apartment is not open for joining.',
                    'error_code' => 'APARTMENT_NOT_OPEN'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Check if user is already a member of this or another apartment
            $existingMembership = ApartmentMember::where('user_id', $user->id)
                ->whereIn('membership_status', ['pending', 'active'])
                ->first();

            if ($existingMembership) {
                $sameApartment = $existingMembership->apartment_id === $lockedApartment->id;
                return response()->json([
                    'message' => $sameApartment
                        ? 'You are already a member of this apartment. Upload your contract from Applications or My Home.'
                        : 'You are already a member of another apartment.',
                    'error_code' => 'ALREADY_MEMBER',
                    'data' => [
                        'apartment_id' => $existingMembership->apartment_id,
                        'membership_status' => $existingMembership->membership_status,
                    ],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Gender suitability check
            if ($lockedApartment->gender_allowed !== 'any' && $lockedApartment->gender_allowed !== $user->gender) {
                return response()->json([
                    'message' => 'This apartment is not suitable for your gender.',
                    'error_code' => 'GENDER_MISMATCH'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Single gender occupancy check
            if ($user->gender === 'male') {
                if ($lockedApartment->female_count > 0) {
                    return response()->json([
                        'message' => 'This apartment is already occupied by female tenants.',
                        'error_code' => 'GENDER_MISMATCH_OCCUPIED'
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                if ($lockedApartment->male_count >= $lockedApartment->capacity) {
                    return response()->json([
                        'message' => 'This apartment is full.',
                        'error_code' => 'APARTMENT_FULL'
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $lockedApartment->male_count++;
            } else {
                // female
                if ($lockedApartment->male_count > 0) {
                    return response()->json([
                        'message' => 'This apartment is already occupied by male tenants.',
                        'error_code' => 'GENDER_MISMATCH_OCCUPIED'
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                if ($lockedApartment->female_count >= $lockedApartment->capacity) {
                    return response()->json([
                        'message' => 'This apartment is full.',
                        'error_code' => 'APARTMENT_FULL'
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $lockedApartment->female_count++;
            }

            // Create membership snapshot as pending
            $member = ApartmentMember::create([
                'apartment_id' => $lockedApartment->id,
                'user_id' => $user->id,
                'gender_snapshot' => $user->gender,
                'membership_status' => 'pending',
            ]);

            // Set status to closed_uploading_contracts if capacity reached
            $currentOccupancy = ($user->gender === 'male') ? $lockedApartment->male_count : $lockedApartment->female_count;
            if ($currentOccupancy >= $lockedApartment->capacity) {
                $lockedApartment->status = 'closed_uploading_contracts';
                $capacityReached = true;
            }

            $lockedApartment->save();

            // Create payment order immediately for this member
            $paymentOrder = $this->createPaymentOrderForMember($lockedApartment, $user, $member);

            return response()->json([
                'message' => 'Successfully joined the apartment. Your membership is pending.',
                'data' => [
                    'membership'    => $member,
                    'apartment'     => $lockedApartment,
                    'payment_order' => $paymentOrder,
                ]
            ]);
        });

        // Fire ApartmentCapacityIsFull event if capacity was reached
        if ($capacityReached) {
            event(new ApartmentCapacityIsFull($apartment->fresh()));
        }

        // Clear caches
        $this->clearApartmentCaches($apartment->id);

        return $result;
    }

    /**
     * Create a Paymob payment order for a newly joined member.
     * Uses or creates the apartment's current pending_payment RentCycle.
     */
    protected function createPaymentOrderForMember(Apartment $apartment, $user, ApartmentMember $member): ?PaymentOrder
    {
        try {
            $deadline = now()->addDays(5);

            // Get or create the single pending_payment rent cycle for this apartment
            $rentCycle = RentCycle::where('apartment_id', $apartment->id)
                ->where('status', 'pending_payment')
                ->first();

            if (!$rentCycle) {
                $latestCycle = RentCycle::where('apartment_id', $apartment->id)
                    ->orderBy('cycle_number', 'desc')
                    ->first();
                $cycleNumber = $latestCycle ? $latestCycle->cycle_number + 1 : 1;

                $rentCycle = RentCycle::create([
                    'apartment_id' => $apartment->id,
                    'cycle_number' => $cycleNumber,
                    'starts_at'    => now(),
                    'ends_at'      => $deadline,
                    'status'       => 'pending_payment',
                ]);
            }

            // Set payment deadline on membership
            $member->update(['payment_deadline' => $deadline]);

            // Calculate amount: full rent + insurance (insurance per member split happens at payout)
            $hasPaidInsurance = InsurancePayment::where('user_id', $user->id)
                ->where('apartment_id', $apartment->id)
                ->exists();

            $totalMembers      = ApartmentMember::where('apartment_id', $apartment->id)
                ->whereIn('membership_status', ['pending', 'active'])
                ->count();
            $N = max($totalMembers, 1);

            $rentShareCents      = (int) (ceil($apartment->price / $N) * 100);
            $insuranceShareCents = $hasPaidInsurance ? 0 : (int) (ceil($apartment->insurance / $N) * 100);
            $totalAmountCents    = $rentShareCents + $insuranceShareCents;

            $idempotencyKey = 'pay_order_' . $rentCycle->id . '_' . $user->id;

            // Skip if order already exists for this member+cycle
            $existing = PaymentOrder::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $paymobService    = resolve(PaymobService::class);
            Log::debug("ApartmentController: Initiating Paymob payment flow for user {$user->id}, amount={$totalAmountCents}");

            $paymobToken      = $paymobService->getAuthToken();
            Log::debug("ApartmentController: Paymob token obtained = " . ($paymobToken ? 'YES' : 'NO'));

            $paymobOrderId    = null;
            $paymobPaymentKey = null;
            $paymentUrl       = null;

            if ($paymobToken) {
                Log::debug("ApartmentController: Creating Paymob order...");
                $paymobOrderId = $paymobService->createOrder($paymobToken, $totalAmountCents, $idempotencyKey);
                Log::debug("ApartmentController: Paymob order ID = " . ($paymobOrderId ?: 'NULL'));

                if ($paymobOrderId) {
                    $billingData = [
                        'first_name'   => $user->profile?->first_name ?? 'Tenant',
                        'last_name'    => $user->profile?->last_name  ?? 'User',
                        'email'        => $user->email ?? 'tenant@example.com',
                        'phone_number' => $user->phone ?? '01000000000',
                    ];
                    Log::debug("ApartmentController: Creating Paymob payment key with orderId={$paymobOrderId}...");
                    $paymobPaymentKey = $paymobService->createPaymentKey($paymobToken, $paymobOrderId, $totalAmountCents, $billingData);
                    Log::debug("ApartmentController: Paymob payment key = " . ($paymobPaymentKey ? 'YES' : 'NO'));

                    if ($paymobPaymentKey) {
                        $paymentUrl = $paymobService->getPaymentUrl($paymobPaymentKey);
                        Log::debug("ApartmentController: Generated payment URL = {$paymentUrl}");
                    } else {
                        Log::error("ApartmentController: Failed to generate payment key - check Paymob integration settings");
                    }
                } else {
                    Log::error("ApartmentController: Failed to create Paymob order - check Paymob auth and API key");
                }
            } else {
                Log::error("ApartmentController: Failed to get Paymob auth token - check PAYMOB_API_KEY in .env");
            }

            $paymentOrder = PaymentOrder::create([
                'idempotency_key'    => $idempotencyKey,
                'rent_cycle_id'      => $rentCycle->id,
                'apartment_id'       => $apartment->id,
                'user_id'            => $user->id,
                'amount_cents'       => $totalAmountCents,
                'breakdown'          => [
                    'rent_cents'         => $rentShareCents,
                    'insurance_cents'    => $insuranceShareCents,
                    'platform_fee_cents' => 0,
                ],
                'paymob_order_id'    => $paymobOrderId !== null ? (string) $paymobOrderId : null,
                'paymob_payment_key' => $paymobPaymentKey,
                'payment_url'        => $paymentUrl,
                'status'             => 'pending',
                'expires_at'         => $deadline,
            ]);

            Log::debug("ApartmentController: PaymentOrder created ID={$paymentOrder->id}, paymob_order_id=" . ($paymobOrderId ?: 'NULL') . ", payment_url=" . ($paymentUrl ? 'SET' : 'NULL'));

            // Notify the user
            Notification::create([
                'user_id'    => $user->id,
                'type'       => 'payment_required',
                'dedupe_key' => 'payment_required_' . $paymentOrder->id,
                'data'       => [
                    'title'        => 'Payment Required',
                    'body'         => 'You have joined an apartment. Please complete your payment within 5 days.',
                    'payment_link' => $paymentUrl ?? '',
                    'amount_egp'   => $totalAmountCents / 100,
                    'apartment_id' => $apartment->id,
                    'deadline'     => $deadline->toIso8601String(),
                ],
                'status' => 'pending',
            ]);

            SendNotification::dispatch($user->id, 'Payment Required',
                'You have joined an apartment. Please complete your payment within 5 days.',
                [
                    'type'         => 'payment_required',
                    'payment_link' => $paymentUrl ?? '',
                    'amount_egp'   => $totalAmountCents / 100,
                    'apartment_id' => $apartment->id,
                    'deadline'     => $deadline->toIso8601String(),
                ],
                ['fcm']
            );

            return $paymentOrder;

        } catch (\Exception $e) {
            Log::error("createPaymentOrderForMember failed for user {$user->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Leave an apartment.
     */
    public function leave(Request $request, string $id)
    {
        $user = $request->user();

        // 1. Retrieve the apartment
        $apartment = Apartment::findOrFail($id);

        // 2. Authorize via policy
        Gate::authorize('leave', $apartment);

        // 3. Perform inside transaction with row lock
        $result = DB::transaction(function () use ($apartment, $user) {
            // Lock the apartment row
            $lockedApartment = Apartment::lockForUpdate()->findOrFail($apartment->id);

            // Find the active or pending membership in this apartment
            $membership = ApartmentMember::where('apartment_id', $lockedApartment->id)
                ->where('user_id', $user->id)
                ->whereIn('membership_status', ['pending', 'active'])
                ->first();

            if (!$membership) {
                return response()->json([
                    'message' => 'You do not have an active or pending membership in this apartment.',
                    'error_code' => 'NOT_MEMBER'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Check if there is the first cycle before payment
            $pendingCycle = RentCycle::where('apartment_id', $lockedApartment->id)
                ->where('status', 'pending_payment')
                ->where('cycle_number', 1)
                ->first();

            if ($pendingCycle) {
                // Cancel the rent cycle
                $pendingCycle->update(['status' => 'cancelled']);

                // Reset payment deadlines
                ApartmentMember::where('apartment_id', $lockedApartment->id)
                    ->whereIn('membership_status', ['pending', 'active'])
                    ->update(['payment_deadline' => null]);

                // Find all remaining members
                $remainingMembers = ApartmentMember::where('apartment_id', $lockedApartment->id)
                    ->where('user_id', '!=', $user->id)
                    ->whereIn('membership_status', ['pending', 'active'])
                    ->get();

                foreach ($remainingMembers as $rem) {
                    $hasPaid = PaymentOrder::where('rent_cycle_id', $pendingCycle->id)
                        ->where('user_id', $rem->user_id)
                        ->where('status', 'paid')
                        ->exists();

                    $remTitle = "Roommate Left - Apartment Reopened";
                    if ($hasPaid) {
                        $remBody = "A roommate has left the apartment. The payment phase is cancelled and the apartment is reopened. Since you already paid, you can request a refund.";
                    } else {
                        $remBody = "A roommate has left the apartment. The payment phase is cancelled and the apartment is reopened. You can leave the apartment now.";
                    }

                    Notification::create([
                        'user_id' => $rem->user_id,
                        'type' => 'apartment_reopened',
                        'dedupe_key' => 'reopened_' . uniqid(),
                        'data' => [
                            'title' => $remTitle,
                            'body' => $remBody,
                            'apartment_id' => $lockedApartment->id,
                            'can_refund' => $hasPaid
                        ],
                        'status' => 'pending'
                    ]);

                    SendNotification::dispatch($rem->user_id, $remTitle, $remBody, [
                        'type' => 'apartment_reopened',
                        'apartment_id' => $lockedApartment->id,
                        'can_refund' => $hasPaid ? 'true' : 'false'
                    ], ['fcm']);
                }
            }

            // Cancel the membership
            $membership->update([
                'membership_status' => 'cancelled'
            ]);

            $this->dataIntegrity->onMemberRemoved($lockedApartment->id, $user->id);

            // Cancel all unpaid payment orders for this user in this apartment
            PaymentOrder::where('user_id', $user->id)
                ->where('apartment_id', $lockedApartment->id)
                ->whereIn('status', ['pending', 'unpaid'])
                ->update(['status' => 'cancelled']);

            // Decrement the correct gender counter
            if ($membership->gender_snapshot === 'male') {
                if ($lockedApartment->male_count > 0) {
                    $lockedApartment->male_count--;
                }
            } else {
                if ($lockedApartment->female_count > 0) {
                    $lockedApartment->female_count--;
                }
            }

            // If apartment status was full, closed_uploading_contracts, or rented, reopen it to open
            if ($lockedApartment->status === 'full' || 
                $lockedApartment->status === 'closed_uploading_contracts' || 
                $lockedApartment->status === 'rented') {
                $lockedApartment->status = 'open';
            }

            $lockedApartment->save();

            return response()->json([
                'message' => 'Successfully left the apartment.',
                'data' => [
                    'membership' => $membership,
                    'apartment' => $lockedApartment
                ]
            ]);
        });

        // Clear caches
        $this->clearApartmentCaches($apartment->id);

        return $result;
    }

    /**
     * Clear relevant caches.
     */
    protected function clearApartmentCaches(string $apartmentId = null)
    {
        if ($apartmentId) {
            Cache::forget("apartment_details_{$apartmentId}");
        }
        
        Cache::forget("apartments_list_gender_male");
        Cache::forget("apartments_list_gender_female");
        Cache::forget("apartments_list_gender_any");
    }
}
