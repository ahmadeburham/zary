<?php

namespace App\Console\Commands;

use App\Models\Apartment;
use App\Models\ApartmentMember;
use App\Models\PaymentOrder;
use App\Models\RentCycle;
use App\Models\Notification;
use App\Jobs\SendNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckPaymentDeadlines extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-payment-deadlines';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks for expired 5-day payment deadlines and handles evictions, refunds, and reopening apartments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info("CheckPaymentDeadlines: Starting execution.");

        // Find members whose payment deadline has passed and who still have pending payment orders
        $expiredMembers = ApartmentMember::whereNotNull('payment_deadline')
            ->where('payment_deadline', '<=', now())
            ->whereIn('membership_status', ['pending', 'active'])
            ->get();

        if ($expiredMembers->isEmpty()) {
            $this->info("No expired payment deadlines found.");
            return;
        }

        // Group by apartment to process apartment-level logic
        $groupedByApartment = $expiredMembers->groupBy('apartment_id');

        foreach ($groupedByApartment as $apartmentId => $expiredMembersInApt) {
            $this->info("Processing expired deadlines for apartment {$apartmentId}");

            DB::transaction(function () use ($apartmentId, $expiredMembersInApt) {
                $apartment = Apartment::lockForUpdate()->find($apartmentId);
                if (!$apartment) return;

                // Find the pending rent cycle for this apartment
                $rentCycle = RentCycle::where('apartment_id', $apartmentId)
                    ->where('status', 'pending_payment')
                    ->first();

                // Get ALL members (not just expired ones) to check paid vs unpaid
                $allMembers = ApartmentMember::where('apartment_id', $apartmentId)
                    ->whereIn('membership_status', ['pending', 'active'])
                    ->get();

                $hasUnpaid = false;

                foreach ($allMembers as $member) {
                    // Check if this member has paid their order
                    $order = $rentCycle
                        ? PaymentOrder::where('rent_cycle_id', $rentCycle->id)
                            ->where('user_id', $member->user_id)
                            ->first()
                        : null;

                    $hasPaid = $order && $order->status === 'paid';
                    $isExpired = $member->payment_deadline && $member->payment_deadline <= now();

                    if (!$hasPaid && $isExpired) {
                        $hasUnpaid = true;

                        // Evict unpaid member
                        $member->update([
                            'membership_status' => 'cancelled',
                            'payment_deadline' => null,
                        ]);

                        // Decrement gender counter
                        if ($member->gender_snapshot === 'male') {
                            if ($apartment->male_count > 0) $apartment->male_count--;
                        } else {
                            if ($apartment->female_count > 0) $apartment->female_count--;
                        }

                        // Notify evicted user
                        $title = "Removed from Apartment";
                        $body = "You were removed because you did not pay your rent share within the 5-day deadline.";
                        $this->notifyUser($member->user_id, $title, $body, [
                            'type' => 'kicked_unpaid',
                            'apartment_id' => $apartment->id,
                        ]);
                    }
                }

                // If any member was evicted, cancel cycle and reopen apartment
                if ($hasUnpaid) {
                    if ($rentCycle) {
                        $rentCycle->update(['status' => 'cancelled']);
                    }

                    $apartment->update(['status' => 'open']);

                    // Notify paid members about reopening
                    $paidMembers = ApartmentMember::where('apartment_id', $apartmentId)
                        ->whereIn('membership_status', ['pending', 'active'])
                        ->get();

                    foreach ($paidMembers as $paidMember) {
                        $paidMember->update([
                            'payment_deadline' => null,
                        ]);

                        $title = "Roommate Payment Expired - Reopened";
                        $body = "One or more roommates did not pay within the deadline. The apartment is reopened. You can request a refund or wait for others to join.";
                        $this->notifyUser($paidMember->user_id, $title, $body, [
                            'type'         => 'apartment_reopened',
                            'apartment_id' => $apartment->id,
                            'can_refund'   => true,
                        ]);
                    }
                }

                $apartment->save();

                // Clear caches
                Cache::forget("apartment_details_{$apartment->id}");
                Cache::forget("apartments_list_gender_male");
                Cache::forget("apartments_list_gender_female");
                Cache::forget("apartments_list_gender_any");
            });
        }

        $this->info("Completed processing expired deadlines.");
    }

    /**
     * Helper to dispatch FCM notification and save to database.
     */
    protected function notifyUser($userId, string $title, string $body, array $data = [])
    {
        Notification::create([
            'user_id' => $userId,
            'type' => $data['type'] ?? 'payment_update',
            'dedupe_key' => 'deadline_' . uniqid(),
            'data' => array_merge(['title' => $title, 'body' => $body], $data),
            'status' => 'pending',
        ]);

        SendNotification::dispatch($userId, $title, $body, $data, ['fcm']);
    }
}
