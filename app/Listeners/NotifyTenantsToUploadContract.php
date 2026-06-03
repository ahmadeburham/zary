<?php

namespace App\Listeners;

use App\Events\ApartmentCapacityIsFull;
use App\Models\ApartmentMember;
use App\Models\Notification;
use App\Jobs\SendNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyTenantsToUploadContract implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(ApartmentCapacityIsFull $event): void
    {
        $apartment = $event->apartment;

        // Retrieve active/pending tenant user IDs
        $tenantIds = ApartmentMember::where('apartment_id', $apartment->id)
            ->whereIn('membership_status', ['pending', 'active'])
            ->pluck('user_id')
            ->toArray();

        if (empty($tenantIds)) {
            Log::warning("NotifyTenantsToUploadContract: No tenants found for apartment ID: {$apartment->id}");
            return;
        }

        $title = "Apartment is Full - Action Required";
        $contractUrl = env('CONTRACT_TEMPLATE_URL', 'https://example.com/contracts/template.pdf');
        $body = "The apartment is now full. Please download the contract, sign it, and upload it within 12 hours or you will be kicked.";
        $data = [
            'type' => 'apartment_full_upload_contract',
            'contract_url' => $contractUrl,
            'deadline' => now()->addHours(12)->toIso8601String(),
            'apartment_id' => $apartment->id,
        ];

        // Track notification in DB for each tenant
        foreach ($tenantIds as $tenantId) {
            Notification::updateOrCreate([
                'user_id' => $tenantId,
                'dedupe_key' => 'apt_full_' . $apartment->id . '_' . $tenantId,
            ], [
                'type' => 'apartment_full_upload_contract',
                'data' => array_merge(['title' => $title, 'body' => $body], $data),
                'status' => 'pending',
            ]);
        }

        // Dispatch FCM job to all tenant IDs
        SendNotification::dispatch($tenantIds, $title, $body, $data, ['fcm']);
    }
}
