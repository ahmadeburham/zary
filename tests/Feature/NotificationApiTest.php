<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user1;
    protected User $user2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user1 = User::factory()->create();
        $this->user2 = User::factory()->create();
    }

    /**
     * Test notification routes require authentication.
     */
    public function test_notification_routes_require_authentication()
    {
        $this->getJson('/api/notifications')->assertStatus(401);
        $this->postJson('/api/notifications/some-uuid/read')->assertStatus(401);
        $this->postJson('/api/notifications/read-all')->assertStatus(401);
    }

    /**
     * Test listing notifications returns only user's notifications.
     */
    public function test_list_notifications()
    {
        // Create notifications for user1
        Notification::create([
            'user_id' => $this->user1->id,
            'type' => 'test_type',
            'dedupe_key' => 'dedupe_1',
            'data' => ['title' => 'Title 1', 'body' => 'Body 1'],
            'status' => 'pending'
        ]);

        Notification::create([
            'user_id' => $this->user1->id,
            'type' => 'test_type',
            'dedupe_key' => 'dedupe_2',
            'data' => ['title' => 'Title 2', 'body' => 'Body 2'],
            'status' => 'pending'
        ]);

        // Create notification for user2
        Notification::create([
            'user_id' => $this->user2->id,
            'type' => 'test_type',
            'dedupe_key' => 'dedupe_3',
            'data' => ['title' => 'Title 3', 'body' => 'Body 3'],
            'status' => 'pending'
        ]);

        $response = $this->actingAs($this->user1, 'sanctum')
            ->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['dedupe_key' => 'dedupe_1'])
            ->assertJsonFragment(['dedupe_key' => 'dedupe_2'])
            ->assertJsonMissing(['dedupe_key' => 'dedupe_3']);
    }

    /**
     * Test marking a single notification as read.
     */
    public function test_mark_notification_as_read()
    {
        $notification = Notification::create([
            'user_id' => $this->user1->id,
            'type' => 'test_type',
            'dedupe_key' => 'dedupe_1',
            'data' => ['title' => 'Title 1', 'body' => 'Body 1'],
            'status' => 'pending'
        ]);

        $response = $this->actingAs($this->user1, 'sanctum')
            ->postJson("/api/notifications/{$notification->id}/read");

        $response->assertStatus(200);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /**
     * Test marking another user's notification as read fails.
     */
    public function test_cannot_mark_other_users_notification_as_read()
    {
        $notification = Notification::create([
            'user_id' => $this->user2->id,
            'type' => 'test_type',
            'dedupe_key' => 'dedupe_2',
            'data' => ['title' => 'Title 2', 'body' => 'Body 2'],
            'status' => 'pending'
        ]);

        $response = $this->actingAs($this->user1, 'sanctum')
            ->postJson("/api/notifications/{$notification->id}/read");

        $response->assertStatus(404);
        $this->assertNull($notification->fresh()->read_at);
    }

    /**
     * Test marking all notifications as read.
     */
    public function test_mark_all_notifications_as_read()
    {
        $n1 = Notification::create([
            'user_id' => $this->user1->id,
            'type' => 'test_type',
            'dedupe_key' => 'dedupe_1',
            'data' => ['title' => 'Title 1', 'body' => 'Body 1'],
            'status' => 'pending'
        ]);

        $n2 = Notification::create([
            'user_id' => $this->user1->id,
            'type' => 'test_type',
            'dedupe_key' => 'dedupe_2',
            'data' => ['title' => 'Title 2', 'body' => 'Body 2'],
            'status' => 'pending'
        ]);

        $other = Notification::create([
            'user_id' => $this->user2->id,
            'type' => 'test_type',
            'dedupe_key' => 'dedupe_3',
            'data' => ['title' => 'Title 3', 'body' => 'Body 3'],
            'status' => 'pending'
        ]);

        $response = $this->actingAs($this->user1, 'sanctum')
            ->postJson('/api/notifications/read-all');

        $response->assertStatus(200);

        $this->assertNotNull($n1->fresh()->read_at);
        $this->assertNotNull($n2->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
    }
}
