<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notifications\NotificationFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class SendNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param array|string $userIds
     * @param string $title
     * @param string $body
     * @param array $data
     * @param array $channels
     */
    public function __construct(
        public array|string $userIds,
        public string $title,
        public string $body,
        public array $data = [],
        public array $channels = ['fcm']
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     *
     * @param NotificationFactory $factory
     * @return void
     */
    public function handle(NotificationFactory $factory): void
    {
        $userIds = (array) $this->userIds;
        
        if (empty($userIds)) {
            Log::warning("SendNotification Job: No user IDs provided.");
            return;
        }

        $users = User::whereIn('id', $userIds)->get();

        if ($users->isEmpty()) {
            Log::warning("SendNotification Job: No users found matching the provided IDs: " . implode(', ', $userIds));
            return;
        }

        foreach ($this->channels as $channelName) {
            try {
                $channel = $factory->make($channelName);
                
                foreach ($users as $user) {
                    try {
                        $channel->send($user, $this->title, $this->body, $this->data);
                    } catch (Exception $e) {
                        Log::error("SendNotification Job: Failed to send notification via channel [{$channelName}] to User ID {$user->id}. Error: " . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                Log::error("SendNotification Job: Failed to resolve or process channel [{$channelName}]. Error: " . $e->getMessage());
            }
        }
    }
}
