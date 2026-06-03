<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use App\Services\FCMService;
use Illuminate\Support\Facades\Log;

class FCMChannel implements NotificationChannelInterface
{
    protected FCMService $fcmService;

    public function __construct(FCMService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    public function send(User $user, string $title, string $body, array $data = []): void
    {
        $token = $user->fcm_token;

        if (!$token) {
            Log::warning("FCM Channel: User ID {$user->id} has no registered FCM token. Skipping dispatch.");
            return;
        }

        $this->fcmService->sendNotification($token, $title, $body, $data);
    }
}
