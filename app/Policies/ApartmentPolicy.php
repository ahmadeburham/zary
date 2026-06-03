<?php

namespace App\Policies;

use App\Models\Apartment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApartmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Apartment $apartment): bool
    {
        if ($user->isAdmin() || $user->id === $apartment->owner_id) {
            return true;
        }

        // Members can always view their own apartment regardless of status
        $isMember = \App\Models\ApartmentMember::where('user_id', $user->id)
            ->where('apartment_id', $apartment->id)
            ->whereIn('membership_status', ['pending', 'active'])
            ->exists();
        if ($isMember) {
            return true;
        }

        // Tenants can view if approved and open
        return $apartment->verification_status === 'approved' && $apartment->status === 'open';
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only owners and admins can create
        if (!$user->isOwner() && !$user->isAdmin()) {
            return false;
        }

        // If admin, bypass payout/verification checks
        if ($user->isAdmin()) {
            return true;
        }

        // Enforce payout_info check
        if (empty($user->payout_info)) {
            abort(response()->json([
                'message' => 'Please provide your payout details in your profile before creating an apartment.',
                'error_code' => 'PAYOUT_REQUIRED'
            ], 422));
        }

        // Enforce is_verified check
        if (!$user->is_verified) {
            abort(response()->json([
                'message' => 'Your profile must be verified (identity document approved) before you can upload an apartment.',
                'error_code' => 'VERIFICATION_REQUIRED'
            ], 403));
        }

        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Apartment $apartment): bool
    {
        // Must be admin or owner of the apartment
        if (!$user->isAdmin() && $user->id !== $apartment->owner_id) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        // Enforce payout_info check
        if (empty($user->payout_info)) {
            abort(response()->json([
                'message' => 'Please provide your payout details in your profile before updating the apartment.',
                'error_code' => 'PAYOUT_REQUIRED'
            ], 422));
        }

        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Apartment $apartment): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->id === $apartment->owner_id) {
            $isInRentOrPayment = \App\Models\RentCycle::where('apartment_id', $apartment->id)
                ->whereIn('status', ['pending_payment', 'active'])
                ->exists();

            if ($isInRentOrPayment) {
                abort(response()->json([
                    'message' => 'You cannot delete this apartment because it is currently in rent or in the payment phase. You can request deletion from the admin.',
                    'error_code' => 'APARTMENT_IN_RENT_OR_PAYMENT'
                ], 403));
            }

            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can join the apartment.
     */
    public function join(User $user, Apartment $apartment): bool
    {
        // Only tenants (rental) can join
        if (!$user->isRental()) {
            return false;
        }

        // Must have passed ID OCR verification
        if (!$user->is_verified) {
            abort(response()->json([
                'message' => 'Your identity document must be verified before you can join an apartment.',
                'error_code' => 'VERIFICATION_REQUIRED'
            ], 403));
        }

        // Must have passed liveness check
        if (!$user->liveness_passed) {
            abort(response()->json([
                'message' => 'You must complete the liveness check before you can join an apartment.',
                'error_code' => 'LIVENESS_REQUIRED'
            ], 403));
        }

        return true;
    }

    /**
     * Determine whether the user can leave the apartment.
     */
    public function leave(User $user, Apartment $apartment): bool
    {
        // Only tenants (rental) can leave
        if (!$user->isRental()) {
            return false;
        }

        // Must be verified
        if (!$user->is_verified) {
            abort(response()->json([
                'message' => 'Your profile must be verified (identity document approved) before you can leave an apartment.',
                'error_code' => 'VERIFICATION_REQUIRED'
            ], 403));
        }

        return true;
    }
}
