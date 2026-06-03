<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\ApartmentDocument;
use App\Models\IdentityDocument;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApartmentCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;
    protected User $admin;
    protected User $tenantMale;
    protected User $tenantFemale;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        // Create roles
        foreach (['rental', 'owner', 'sponsor', 'admin'] as $roleName) {
            Role::firstOrCreate(['role' => $roleName]);
        }

        // Create Owner
        $this->owner = User::factory()->create([
            'gender' => 'male',
            'is_verified' => false,
            'payout_info' => null,
        ]);
        $this->owner->roles()->attach(Role::where('role', 'owner')->first()->id);

        // Create Admin
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('role', 'admin')->first()->id);

        // Create Tenant Male
        $this->tenantMale = User::factory()->create(['gender' => 'male']);
        $this->tenantMale->roles()->attach(Role::where('role', 'rental')->first()->id);

        // Create Tenant Female
        $this->tenantFemale = User::factory()->create(['gender' => 'female']);
        $this->tenantFemale->roles()->attach(Role::where('role', 'rental')->first()->id);

        // Delete existing apartments to ensure listing count assertions start from zero
        Apartment::query()->delete();
    }

    /**
     * Test: Deny creating or updating if payout_info is missing.
     */
    public function test_deny_apartment_create_and_update_if_payout_info_missing()
    {
        // 1. Create Attempt: Should fail with 422 payout required
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/apartments', [
                'price' => 1200,
                'insurance' => 500,
                'capacity' => 4,
                'gender_allowed' => 'male',
                'rooms_count' => 2,
                'beds_count' => 4,
                'latitude' => 30.0444,
                'longitude' => 31.2357,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error_code' => 'PAYOUT_REQUIRED'
            ]);

        // 2. Setup payout info but owner is not verified yet
        $this->owner->update(['payout_info' => 'Bank ABC, Acc: 123']);

        // Create should now fail with 403 verification required (since owner.is_verified is false)
        $response2 = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/apartments', [
                'price' => 1200,
                'insurance' => 500,
                'capacity' => 4,
                'gender_allowed' => 'male',
                'rooms_count' => 2,
                'beds_count' => 4,
                'latitude' => 30.0444,
                'longitude' => 31.2357,
            ]);

        $response2->assertStatus(403)
            ->assertJson([
                'error_code' => 'VERIFICATION_REQUIRED'
            ]);
    }

    /**
     * Test: Owner profile payout update and identity document upload flow.
     */
    public function test_owner_payout_and_identity_document_flow()
    {
        // 1. Update payout
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/owner/payout', [
                'payout_info' => 'My PayPal payout details'
            ]);

        $response->assertStatus(200);
        $this->assertEquals('My PayPal payout details', $this->owner->fresh()->payout_info);

        // 2. Upload identity document (starts as pending)
        $file = UploadedFile::fake()->image('national_id.jpg');
        $uploadResponse = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/owner/identity-document', [
                'type' => 'national_id',
                'document_number' => '12345678901234',
                'file' => $file
            ]);

        $uploadResponse->assertStatus(201);
        $docId = $uploadResponse->json('data.id');

        $this->assertDatabaseHas('identity_documents', [
            'id' => $docId,
            'user_id' => $this->owner->id,
            'status' => 'pending',
            'is_verified' => 0
        ]);

        // Enforce 1:1 constraint: upload another one, it should replace the first one
        $file2 = UploadedFile::fake()->image('passport.jpg');
        $uploadResponse2 = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/owner/identity-document', [
                'type' => 'passport',
                'document_number' => 'A99887766',
                'file' => $file2
            ]);

        $uploadResponse2->assertStatus(201);
        $newDocId = $uploadResponse2->json('data.id');

        // Old document record should be deleted
        $this->assertDatabaseMissing('identity_documents', ['id' => $docId]);
        $this->assertDatabaseHas('identity_documents', [
            'id' => $newDocId,
            'document_number' => 'A99887766',
            'status' => 'pending'
        ]);

        // 3. Admin rejects identity document
        $rejectResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/identity-documents/{$newDocId}/reject", [
                'reason' => 'Photo is too blurry.'
            ]);

        $rejectResponse->assertStatus(200);
        $this->assertDatabaseHas('identity_documents', [
            'id' => $newDocId,
            'status' => 'rejected',
            'rejection_reason' => 'Photo is too blurry.',
            'is_verified' => 0
        ]);
        $this->assertFalse($this->owner->fresh()->is_verified);

        // Verify a notification was created
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->owner->id,
            'type' => 'identity_verified'
        ]);

        // 4. Admin approves identity document
        $approveResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/identity-documents/{$newDocId}/verify");

        $approveResponse->assertStatus(200);
        $this->assertDatabaseHas('identity_documents', [
            'id' => $newDocId,
            'status' => 'approved',
            'is_verified' => 1
        ]);
        $this->assertTrue($this->owner->fresh()->is_verified);
    }

    /**
     * Test: Verified owner creates, updates, lists, and deletes apartments.
     */
    public function test_apartment_crud_and_publishing_workflow()
    {
        // 1. Setup owner with payout and verification status
        $this->owner->update([
            'payout_info' => 'Bank details here',
            'is_verified' => true
        ]);

        // 2. Create apartment with photos and document
        $photo1 = UploadedFile::fake()->image('living_room.png');
        $photo2 = UploadedFile::fake()->image('kitchen.png');
        $contractFile = UploadedFile::fake()->create('contract.pdf', 100);

        $createResponse = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/apartments', [
                'price' => 1500.00,
                'insurance' => 600.00,
                'capacity' => 3,
                'gender_allowed' => 'male',
                'rooms_count' => 2,
                'beds_count' => 3,
                'has_ac' => true,
                'has_water' => true,
                'has_gas' => false,
                'is_furnished' => true,
                'latitude' => 29.9868,
                'longitude' => 31.1234,
                'rent_duration' => 6,
                'photos' => [$photo1, $photo2],
                'document' => $contractFile,
                'document_type' => 'ownership_deed'
            ]);

        $createResponse->assertStatus(201);
        $aptId = $createResponse->json('data.id');
        $docId = $createResponse->json('data.document.id');

        $this->assertDatabaseHas('apartments', [
            'id' => $aptId,
            'price' => 1500.00,
            'status' => 'draft',
            'verification_status' => 'pending'
        ]);

        $this->assertDatabaseHas('apartment_documents', [
            'id' => $docId,
            'apartment_id' => $aptId,
            'status' => 'pending',
            'document_type' => 'ownership_deed'
        ]);

        // Verify photos count
        $this->assertCount(2, Apartment::find($aptId)->photos);

        // 3. Admin rejects apartment document
        $rejectResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/apartment-documents/{$docId}/reject", [
                'reason' => 'Contract signature is missing.'
            ]);

        $rejectResponse->assertStatus(200);
        $this->assertDatabaseHas('apartment_documents', [
            'id' => $docId,
            'status' => 'rejected',
            'rejection_reason' => 'Contract signature is missing.'
        ]);
        $this->assertDatabaseHas('apartments', [
            'id' => $aptId,
            'verification_status' => 'rejected'
        ]);

        // 4. Update the apartment with a corrected contract document (1:1 replaces old document)
        $newContractFile = UploadedFile::fake()->create('contract_fixed.pdf', 150);
        $updateResponse = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/apartments/{$aptId}", [
                'price' => 1600.00, // Update price too
                'document' => $newContractFile,
                'document_type' => 'ownership_deed'
            ]);

        $updateResponse->assertStatus(200);
        $newDocId = $updateResponse->json('data.document.id');

        // Old document record should be deleted, new one added as pending
        $this->assertDatabaseMissing('apartment_documents', ['id' => $docId]);
        $this->assertDatabaseHas('apartment_documents', [
            'id' => $newDocId,
            'apartment_id' => $aptId,
            'status' => 'pending'
        ]);
        
        // Apartment verification status resets to pending, status is draft
        $this->assertDatabaseHas('apartments', [
            'id' => $aptId,
            'price' => 1600.00,
            'status' => 'draft',
            'verification_status' => 'pending'
        ]);

        // 5. Admin verifies the contract document -> auto publishes apartment
        $verifyResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/apartment-documents/{$newDocId}/verify");

        $verifyResponse->assertStatus(200);
        $this->assertDatabaseHas('apartment_documents', [
            'id' => $newDocId,
            'status' => 'approved'
        ]);
        $this->assertDatabaseHas('apartments', [
            'id' => $aptId,
            'status' => 'open',
            'verification_status' => 'approved'
        ]);

        // 6. Test list caching and tenant visibility based on gender suitability
        // Clear caches for test consistency
        Cache::forget("apartments_list_gender_male");
        Cache::forget("apartments_list_gender_female");

        // Tenant Male calls listing -> should see this apartment (allowed: male)
        $listMale = $this->actingAs($this->tenantMale, 'sanctum')->getJson('/api/apartments');
        $listMale->assertStatus(200);
        $this->assertCount(1, $listMale->json('data'));

        // Tenant Female calls listing -> should NOT see this apartment (allowed: male)
        $listFemale = $this->actingAs($this->tenantFemale, 'sanctum')->getJson('/api/apartments');
        $listFemale->assertStatus(200);
        $this->assertCount(0, $listFemale->json('data'));

        // 7. Test details caching
        $detailsKey = "apartment_details_{$aptId}";
        Cache::forget($detailsKey);

        $showResponse = $this->actingAs($this->tenantMale, 'sanctum')->getJson("/api/apartments/{$aptId}");
        $showResponse->assertStatus(200);
        $this->assertTrue(Cache::has($detailsKey));

        // Update should invalidate cache
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/apartments/{$aptId}", ['price' => 1700]);
        $this->assertFalse(Cache::has($detailsKey));

        // 8. Delete apartment
        $deleteResponse = $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/apartments/{$aptId}");
        $deleteResponse->assertStatus(200);
        $this->assertDatabaseMissing('apartments', ['id' => $aptId]);
        $this->assertDatabaseMissing('apartment_documents', ['apartment_id' => $aptId]);
    }

    /**
     * Test: Idempotency keys are handled correctly.
     */
    public function test_apartment_create_is_idempotent()
    {
        $this->owner->update([
            'payout_info' => 'PayPal account info',
            'is_verified' => true
        ]);

        $idempotencyKey = 'apt-key-' . uniqid();
        $payload = [
            'price' => 1800,
            'insurance' => 500,
            'capacity' => 2,
            'gender_allowed' => 'any',
            'rooms_count' => 1,
            'beds_count' => 2,
            'latitude' => 30.0444,
            'longitude' => 31.2357,
        ];

        // Send first request with key header
        $response1 = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/apartments', $payload);

        $response1->assertStatus(201);
        $aptId1 = $response1->json('data.id');

        // Send second request with same key header
        $response2 = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/apartments', $payload);

        $response2->assertStatus(201);
        $aptId2 = $response2->json('data.id');

        // Should return same apartment ID (no duplicate created)
        $this->assertEquals($aptId1, $aptId2);

        // Count apartments owned by owner should be exactly 1
        $this->assertEquals(1, Apartment::where('owner_id', $this->owner->id)->count());
    }
}
