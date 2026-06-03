<?php

namespace App\Services\Notifications;

use App\Services\Notifications\Channels\FCMChannel;
use App\Services\Notifications\Channels\NotificationChannelInterface;
use App\Services\Notifications\Channels\SMSChannel;
use InvalidArgumentException;

class NotificationFactory
{
    /**
     * Get the notification channel instance by name.
     */
    public function make(string $channel): NotificationChannelInterface
    {
        return match (strtolower($channel)) {
            'fcm' => app(FCMChannel::class),
            'sms' => app(SMSChannel::class),
            default => throw new InvalidArgumentException("Notification channel [{$channel}] is not supported."),
        };
    }
}
