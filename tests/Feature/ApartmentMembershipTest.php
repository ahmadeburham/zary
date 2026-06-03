<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\ApartmentMember;
use App\Models\IdentityDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use MatanYadaev\EloquentSpatial\Objects\Point;

class ApartmentMembershipTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $owner;
    protected User $tenantMale;
    protected User $tenantFemale;
    protected User $unverifiedTenant;
    protected Apartment $aptMale;
    protected Apartment $aptFemale;
    protected Apartment $aptAny;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

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
            'payout_info' => 'PayPal details',
        ]);
        $this->owner->roles()->attach(Role::where('role', 'owner')->first()->id);

        // Create verified Male Tenant
        $this->tenantMale = User::factory()->create([
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $this->tenantMale->roles()->attach(Role::where('role', 'rental')->first()->id);

        // Create verified Female Tenant
        $this->tenantFemale = User::factory()->create([
            'gender' => 'female',
            'is_verified' => true,
        ]);
        $this->tenantFemale->roles()->attach(Role::where('role', 'rental')->first()->id);

        // Create unverified Tenant
        $this->unverifiedTenant = User::factory()->create([
            'gender' => 'male',
            'is_verified' => false,
        ]);
        $this->unverifiedTenant->roles()->attach(Role::where('role', 'rental')->first()->id);

        // Create male-only apartment
        $this->aptMale = Apartment::create([
            'owner_id' => $this->owner->id,
            'price' => 1000.00,
            'insurance' => 400.00,
            'capacity' => 2,
            'male_count' => 0,
            'female_count' => 0,
            'gender_allowed' => 'male',
            'rooms_count' => 1,
            'beds_count' => 2,
            'status' => 'open',
            'verification_status' => 'approved',
            'location' => new Point(30.0, 31.0),
        ]);

        // Create female-only apartment
        $this->aptFemale = Apartment::create([
            'owner_id' => $this->owner->id,
            'price' => 1200.00,
            'insurance' => 500.00,
            'capacity' => 2,
            'male_count' => 0,
            'female_count' => 0,
            'gender_allowed' => 'female',
            'rooms_count' => 1,
            'beds_count' => 2,
            'status' => 'open',
            'verification_status' => 'approved',
            'location' => new Point(30.0, 31.0),
        ]);

        // Create any-gender apartment
        $this->aptAny = Apartment::create([
            'owner_id' => $this->owner->id,
            'price' => 1500.00,
            'insurance' => 600.00,
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
    }

    /**
     * Test: Unverified tenants cannot join or leave apartments.
     */
    public function test_unverified_tenant_cannot_join_or_leave()
    {
        // Join should fail with 403
        $responseJoin = $this->actingAs($this->unverifiedTenant, 'sanctum')
            ->postJson("/api/apartments/{$this->aptMale->id}/join");

        $responseJoin->assertStatus(403)
            ->assertJson(['error_code' => 'VERIFICATION_REQUIRED']);

        // Leave should fail with 403
        $responseLeave = $this->actingAs($this->unverifiedTenant, 'sanctum')
            ->postJson("/api/apartments/{$this->aptMale->id}/leave");

        $responseLeave->assertStatus(403)
            ->assertJson(['error_code' => 'VERIFICATION_REQUIRED']);
    }

    /**
     * Test: Tenant document upload route exists and works.
     */
    public function test_tenant_identity_document_upload_flow()
    {
        $file = UploadedFile::fake()->image('my_id.jpg');
        $response = $this->actingAs($this->unverifiedTenant, 'sanctum')
            ->postJson('/api/tenant/identity-document', [
                'type' => 'national_id',
                'document_number' => '11223344556677',
                'file' => $file,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('identity_documents', [
            'user_id' => $this->unverifiedTenant->id,
            'document_number' => '11223344556677',
            'status' => 'pending',
        ]);
    }

    /**
     * Test: Successful join and leave flow.
     */
    public function test_successful_join_and_leave_flow()
    {
        // 1. Male tenant joins male apartment
        $responseJoin = $this->actingAs($this->tenantMale, 'sanctum')
            ->postJson("/api/apartments/{$this->aptMale->id}/join");

        $responseJoin->assertStatus(200);
        $this->assertDatabaseHas('apartment_members', [
            'apartment_id' => $this->aptMale->id,
            'user_id' => $this->tenantMale->id,
            'membership_status' => 'pending',
            'gender_snapshot' => 'male',
        ]);

        $this->assertEquals(1, $this->aptMale->fresh()->male_count);

        // 2. Male tenant tries to join again -> should fail
        $responseDoubleJoin = $this->actingAs($this->tenantMale, 'sanctum')
            ->postJson("/api/apartments/{$this->aptMale->id}/join");

        $responseDoubleJoin->assertStatus(422)
            ->assertJson(['error_code' => 'ALREADY_MEMBER']);

        // 3. Male tenant leaves the apartment
        $responseLeave = $this->actingAs($this->tenantMale, 'sanctum')
            ->postJson("/api/apartments/{$this->aptMale->id}/leave");

        $responseLeave->assertStatus(200);
        $this->assertDatabaseHas('apartment_members', [
            'apartment_id' => $this->aptMale->id,
            'user_id' => $this->tenantMale->id,
            'membership_status' => 'cancelled',
        ]);

        $this->assertEquals(0, $this->aptMale->fresh()->male_count);
    }

    /**
     * Test: Gender suitability constraint.
     */
    public function test_gender_suitability_constraint()
    {
        // Female tenant tries to join male apartment -> should fail
        $response = $this->actingAs($this->tenantFemale, 'sanctum')
            ->postJson("/api/apartments/{$this->aptMale->id}/join");

        $response->assertStatus(422)
            ->assertJson(['error_code' => 'GENDER_MISMATCH']);
    }

    /**
     * Test: Apartment gender lock (single-gender rental constraint).
     */
    public function test_single_gender_rental_lock_on_any_apartment()
    {
        // 1. Female tenant joins any-gender apartment
        $response1 = $this->actingAs($this->tenantFemale, 'sanctum')
            ->postJson("/api/apartments/{$this->aptAny->id}/join");

        $response1->assertStatus(200);
        $this->assertEquals(1, $this->aptAny->fresh()->female_count);

        // 2. Male tenant tries to join the same any-gender apartment -> should fail (locked to female)
        $response2 = $this->actingAs($this->tenantMale, 'sanctum')
            ->postJson("/api/apartments/{$this->aptAny->id}/join");

        $response2->assertStatus(422)
            ->assertJson(['error_code' => 'GENDER_MISMATCH_OCCUPIED']);
    }

    /**
     * Test: Capacity limits and auto full status.
     */
    public function test_capacity_limits_and_full_status()
    {
        // Set capacity of aptMale to 1 for this test
        $this->aptMale->update(['capacity' => 1]);

        // 1. Male tenant joins
        $response1 = $this->actingAs($this->tenantMale, 'sanctum')
            ->postJson("/api/apartments/{$this->aptMale->id}/join");

        $response1->assertStatus(200);
        $this->assertEquals(1, $this->aptMale->fresh()->male_count);
        $this->assertEquals('closed_uploading_contracts', $this->aptMale->fresh()->status);

        // Create another verified Male Tenant
        $tenantMale2 = User::factory()->create([
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $tenantMale2->roles()->attach(Role::where('role', 'rental')->first()->id);

        // 2. Second male tenant tries to join -> should fail with APARTMENT_NOT_OPEN because status is 'full'
        $response2 = $this->actingAs($tenantMale2, 'sanctum')
            ->postJson("/api/apartments/{$this->aptMale->id}/join");

        $response2->assertStatus(422)
            ->assertJson(['error_code' => 'APARTMENT_NOT_OPEN']);

        // 3. First tenant leaves -> status should go back to 'open'
        $responseLeave = $this->actingAs($this->tenantMale, 'sanctum')
            ->postJson("/api/apartments/{$this->aptMale->id}/leave");

        $responseLeave->assertStatus(200);
        $this->assertEquals('open', $this->aptMale->fresh()->status);
        $this->assertEquals(0, $this->aptMale->fresh()->male_count);
    }
}
