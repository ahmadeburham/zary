<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\ApartmentMember;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Services\DataIntegrityService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApartmentMembersController extends Controller
{
    public function __construct(
        protected DataIntegrityService $dataIntegrity,
    ) {}

    /** GET /api/apartments/{id}/members */
    public function index(Request $request, $id)
    {
        $apartment = Apartment::findOrFail($id);
        $user = $request->user();
        if (!$user->isAdmin() && $apartment->owner_id !== $user->id) abort(403);

        // Auto-remove orphaned membership rows where the user was deleted
        ApartmentMember::where('apartment_id', $id)
            ->whereDoesntHave('user')
            ->delete();

        $members = ApartmentMember::where('apartment_id', $id)
            ->with('user.profile')
            ->get();
        return response()->json(['data' => $members]);
    }

    /** POST /api/apartments/{id}/add-member — add tenant by email */
    public function add(Request $request, $id)
    {
        $request->validate(['email' => 'required|email']);
        $apartment = Apartment::findOrFail($id);
        $user      = $request->user();
        if (!$user->isAdmin() && $apartment->owner_id !== $user->id) abort(403);

        $tenant = User::where('email', $request->input('email'))->first();
        if (!$tenant) {
            return response()->json(['message' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }
        if (ApartmentMember::where('apartment_id', $id)->where('user_id', $tenant->id)->exists()) {
            return response()->json(['message' => 'User is already a member.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $member = ApartmentMember::create([
            'apartment_id'      => $id,
            'user_id'           => $tenant->id,
            'gender_snapshot'   => $tenant->gender,
            'membership_status' => 'pending',
        ]);
        return response()->json(['message' => 'Member added.', 'data' => $member], Response::HTTP_CREATED);
    }

    /** POST /api/apartments/{id}/remove-member — remove tenant by user_id or member_id */
    public function remove(Request $request, $id)
    {
        $request->validate(['user_id' => 'required_without:member_id', 'member_id' => 'required_without:user_id']);
        $apartment = Apartment::findOrFail($id);
        $user      = $request->user();
        if (!$user->isAdmin() && $apartment->owner_id !== $user->id) abort(403);

        // Find by member_id first (handles orphaned/deleted-user rows), then by user_id
        if ($request->filled('member_id')) {
            $member = ApartmentMember::where('apartment_id', $id)
                ->where('id', $request->input('member_id'))
                ->first();
        } else {
            $member = ApartmentMember::where('apartment_id', $id)
                ->where('user_id', $request->input('user_id'))
                ->first();
        }

        if (!$member) {
            return response()->json(['message' => 'Member not found.'], Response::HTTP_NOT_FOUND);
        }

        // Hard-delete if the linked user no longer exists, otherwise soft-cancel
        if (!$member->user) {
            $member->delete();
        } else {
            $member->update(['membership_status' => 'cancelled']);
            $this->dataIntegrity->onMemberRemoved($id, $member->user_id);
        }

        return response()->json(['message' => 'Member removed.']);
    }
}
