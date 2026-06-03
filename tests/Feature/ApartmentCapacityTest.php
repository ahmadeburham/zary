<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\ApartmentMember;
use App\Models\TenantContract;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\IdentityDocument;
use App\Jobs\SendNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use MatanYadaev\EloquentSpatial\Objects\Point;

class ApartmentCapacityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $owner;
    protected User $tenant1;
    protected User $tenant2;
    protected Apartment $apartment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake([SendNotification::class]);

        // Create roles
        foreach (['rental', 'owner', 'sponsor', 'admin'] as $roleName) {
            Role::firstOrCreate(['role' => $roleName]);
        }

        // Create Admin
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('role', 'admin')->first()->id);

        // Create Owner
        $this->owner = User::factory()->create([
            'is_verified' => true,
            'payout_info' => 'PayPal info',
        ]);
        $this->owner->roles()->attach(Role::where('role', 'owner')->first()->id);

        // Create verified Tenant 1
        $this->tenant1 = User::factory()->create([
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $this->tenant1->roles()->attach(Role::where('role', 'rental')->first()->id);

        // Create verified Tenant 2
        $this->tenant2 = User::factory()->create([
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $this->tenant2->roles()->attach(Role::where('role', 'rental')->first()->id);

        // Create an apartment with capacity of 2
        $this->apartment = Apartment::create([
            'owner_id' => $this->owner->id,
            'price' => 1500.00,
            'insurance' => 500.00,
            'capacity' => 2,
            'male_count' => 0,
            'female_count' => 0,
            'gender_allowed' => 'any',
            'rooms_count' => 2,
            'beds_count' => 2,
            'status' => 'open',
            'verification_status' => 'approved',
            'location' => new Point(30.0, 31.0),
        ]);

        // Create an approved identity document for both tenants
        IdentityDocument::create([
            'user_id' => $this->tenant1->id,
            'type' => 'national_id',
            'document_number' => '11111111111111',
            'path' => 'docs/id1.jpg',
            'status' => 'approved',
            'is_verified' => true,
        ]);

        IdentityDocument::create([
            'user_id' => $this->tenant2->id,
            'type' => 'national_id',
            'document_number' => '22222222222222',
            'path' => 'docs/id2.jpg',
            'status' => 'approved',
            'is_verified' => true,
        ]);
    }

    /**
     * Test the full workflow of capacity limit trigger, contract uploading, rejection, approval, and payment deadline.
     */
    public function test_apartment_capacity_and_contracts_workflow()
    {
        // 1. Tenant 1 joins the apartment
        $responseJoin1 = $this->actingAs($this->tenant1, 'sanctum')
            ->postJson("/api/apartments/{$this->apartment->id}/join");

        $responseJoin1->assertStatus(200);
        $this->assertEquals('open', $this->apartment->fresh()->status);
        $this->assertEquals(1, $this->apartment->fresh()->male_count);

        // 2. Tenant 2 joins the apartment (reaches capacity)
        $responseJoin2 = $this->actingAs($this->tenant2, 'sanctum')
            ->postJson("/api/apartments/{$this->apartment->id}/join");

        $responseJoin2->assertStatus(200);

        // Apartment status should immediately transition to 'closed_uploading_contracts'
        $this->assertEquals('closed_uploading_contracts', $this->apartment->fresh()->status);
        $this->assertEquals(2, $this->apartment->fresh()->male_count);

        // Assert that persistent notifications were created in the DB for both tenants
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenant1->id,
            'type' => 'apartment_full_upload_contract'
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenant2->id,
            'type' => 'apartment_full_upload_contract'
        ]);

        // Assert the SendNotification job was pushed to the queue for fcm sending
        Queue::assertPushed(SendNotification::class, function ($job) {
            return $job->title === 'Apartment is Full - Action Required';
        });

        // 3. Tenant 1 uploads a contract
        $file1 = UploadedFile::fake()->create('contract1.pdf', 200);
        $responseUpload1 = $this->actingAs($this->tenant1, 'sanctum')
            ->postJson('/api/tenant/contracts', [
                'file' => $file1
            ]);

        $responseUpload1->assertStatus(201);
        $contractId1 = $responseUpload1->json('data.id');

        $this->assertDatabaseHas('tenants_contracts', [
            'id' => $contractId1,
            'user_id' => $this->tenant1->id,
            'status' => 'pending'
        ]);

        // 4. Tenant 2 uploads a contract
        $file2 = UploadedFile::fake()->create('contract2.pdf', 300);
        $responseUpload2 = $this->actingAs($this->tenant2, 'sanctum')
            ->postJson('/api/tenant/contracts', [
                'file' => $file2
            ]);

        $responseUpload2->assertStatus(201);
        $contractId2 = $responseUpload2->json('data.id');

        // Assert admin notification was triggered because all members uploaded contracts
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->id,
            'type' => 'apartment_contracts_ready'
        ]);

        // 5. Admin rejects Tenant 2's contract
        $responseReject = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/tenant-contracts/{$contractId2}/reject", [
                'reason' => 'Missing witness signatures.'
            ]);

        $responseReject->assertStatus(200);
        $this->assertDatabaseHas('tenants_contracts', [
            'id' => $contractId2,
            'status' => 'refused'
        ]);

        // Assert Tenant 2 gets rejected notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenant2->id,
            'type' => 'contract_verified',
        ]);

        $notification = Notification::where('user_id', $this->tenant2->id)
            ->where('type', 'contract_verified')
            ->first();
        $this->assertNotNull($notification);
        $this->assertEquals('rejected', $notification->data['status']);
        $this->assertEquals('Missing witness signatures.', $notification->data['rejection_reason']);

        // 6. Tenant 2 uploads revised contract
        $file2Revised = UploadedFile::fake()->create('contract2_fixed.pdf', 350);
        $responseUploadRevised = $this->actingAs($this->tenant2, 'sanctum')
            ->postJson('/api/tenant/contracts', [
                'file' => $file2Revised
            ]);

        $responseUploadRevised->assertStatus(201);
        $newContractId2 = $responseUploadRevised->json('data.id');

        // Old contract should have been replaced/deleted from DB
        $this->assertDatabaseMissing('tenants_contracts', ['id' => $contractId2]);

        // 7. Admin verifies Tenant 1's contract
        $responseVerify1 = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/tenant-contracts/{$contractId1}/verify");

        $responseVerify1->assertStatus(200);
        $this->assertDatabaseHas('tenants_contracts', [
            'id' => $contractId1,
            'status' => 'accepted'
        ]);

        // 8. Admin verifies Tenant 2's revised contract (reaches all contracts verified)
        $responseVerify2 = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/tenant-contracts/{$newContractId2}/verify");

        $responseVerify2->assertStatus(200);
        $this->assertDatabaseHas('tenants_contracts', [
            'id' => $newContractId2,
            'status' => 'accepted'
        ]);

        // Assert both tenants' payment deadlines were set to 12 hours from now
        $this->assertNotNull(ApartmentMember::where('user_id', $this->tenant1->id)->first()->payment_deadline);
        $this->assertNotNull(ApartmentMember::where('user_id', $this->tenant2->id)->first()->payment_deadline);

        // Assert payment required notification was sent to both tenants
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenant1->id,
            'type' => 'payment_required'
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenant2->id,
            'type' => 'payment_required'
        ]);
    }
}
