<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;

interface NotificationChannelInterface
{
    /**
     * Send notification to a specific user.
     */
    public function send(User $user, string $title, string $body, array $data = []): void;
}
