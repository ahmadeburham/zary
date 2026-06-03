<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\ApartmentMember;
use App\Models\IdentityVerification;
use App\Models\PaymentOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps related records consistent when users/apartments/memberships are removed.
 */
class DataIntegrityService
{
    public function purgeUserRelatedData(User $user): void
    {
        DB::transaction(function () use ($user) {
            ApartmentMember::where('user_id', $user->id)->update(['membership_status' => 'cancelled']);
            PaymentOrder::where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'cancelled']);
            IdentityVerification::where('user_id', $user->id)->delete();
            $user->tokens()->delete();
        });
        Log::info('DataIntegrityService: purged related data for user', ['user_id' => $user->id]);
    }

    public function onApartmentDeleted(Apartment $apartment): void
    {
        ApartmentMember::where('apartment_id', $apartment->id)
            ->whereIn('membership_status', ['pending', 'active'])
            ->update(['membership_status' => 'cancelled']);

        PaymentOrder::where('apartment_id', $apartment->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);
    }

    public function onMemberRemoved(string $apartmentId, string $userId): void
    {
        PaymentOrder::where('apartment_id', $apartmentId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);
    }
}
