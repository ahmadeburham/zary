<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class SMSChannel implements NotificationChannelInterface
{
    public function send(User $user, string $title, string $body, array $data = []): void
    {
        $phone = $user->phone;

        if (!$phone) {
            Log::warning("SMS Channel: User ID {$user->id} has no registered phone number. Skipping dispatch.");
            return;
        }

        // Simulate sending SMS by logging the action
        Log::info("SMS Channel: Notification sent to phone '{$phone}' for user ID {$user->id}. Title: '{$title}', Body: '{$body}', Data: " . json_encode($data));
    }
}
