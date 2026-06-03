<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\ApartmentDocument;
use App\Models\ApartmentMember;
use App\Models\IdentityDocument;
use App\Models\TenantContract;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use MatanYadaev\EloquentSpatial\Objects\Point;

class AdminModerationDetailsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $owner;
    protected User $tenant;
    protected Apartment $apartment;
    protected IdentityDocument $ownerIdDoc;
    protected ApartmentDocument $aptDoc;
    protected IdentityDocument $tenantIdDoc;
    protected TenantContract $tenantContract;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache to avoid test pollution
        Cache::flush();

        // Setup roles
        foreach (['rental', 'owner', 'sponsor', 'admin'] as $roleName) {
            Role::firstOrCreate(['role' => $roleName]);
        }

        // Setup Admin
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('role', 'admin')->first()->id);

        // Setup Owner
        $this->owner = User::factory()->create([
            'is_verified' => true,
            'payout_info' => 'PayPal details',
        ]);
        $this->owner->roles()->attach(Role::where('role', 'owner')->first()->id);

        // Owner identity document
        $this->ownerIdDoc = IdentityDocument::create([
            'user_id' => $this->owner->id,
            'type' => 'passport',
            'document_number' => 'OWNER12345',
            'path' => 'identity_documents/owner.pdf',
            'status' => 'pending',
            'is_verified' => false,
        ]);

        // Setup Apartment
        $this->apartment = Apartment::create([
            'owner_id' => $this->owner->id,
            'price' => 2000.00,
            'insurance' => 500.00,
            'capacity' => 3,
            'male_count' => 0,
            'female_count' => 0,
            'gender_allowed' => 'male',
            'rooms_count' => 2,
            'beds_count' => 3,
            'status' => 'open',
            'verification_status' => 'approved',
            'location' => new Point(30.0, 31.0),
        ]);

        // Apartment ownership document
        $this->aptDoc = ApartmentDocument::create([
            'apartment_id' => $this->apartment->id,
            'user_id' => $this->owner->id,
            'path' => 'apartment_documents/proof.pdf',
            'document_type' => 'ownership_proof',
            'status' => 'pending',
        ]);

        // Setup Tenant
        $this->tenant = User::factory()->create([
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $this->tenant->roles()->attach(Role::where('role', 'rental')->first()->id);

        // Tenant identity document
        $this->tenantIdDoc = IdentityDocument::create([
            'user_id' => $this->tenant->id,
            'type' => 'national_id',
            'document_number' => 'TENANT12345',
            'path' => 'identity_documents/tenant.pdf',
            'status' => 'approved',
            'is_verified' => true,
        ]);

        // Join Tenant to apartment
        ApartmentMember::create([
            'apartment_id' => $this->apartment->id,
            'user_id' => $this->tenant->id,
            'gender_snapshot' => 'male',
            'membership_status' => 'pending',
        ]);

        // Tenant signed contract
        $this->tenantContract = TenantContract::create([
            'user_id' => $this->tenant->id,
            'apartment_id' => $this->apartment->id,
            'path' => 'tenants_contracts/contract.pdf',
            'type' => 'signed_contract',
            'status' => 'pending',
        ]);
    }

    /**
     * Test admin can fetch all moderation details for an apartment.
     */
    public function test_admin_can_retrieve_moderation_details()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/apartments/{$this->apartment->id}/moderation-details");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'apartment' => [
                        'id', 'owner_id', 'price', 'insurance', 'capacity',
                        'male_count', 'female_count', 'status', 'verification_status'
                    ],
                    'owner' => [
                        'id', 'name', 'email', 'phone', 'is_verified'
                    ],
                    'owner_identity_document' => [
                        'id', 'type', 'document_number', 'path', 'status', 'is_verified'
                    ],
                    'ownership_document' => [
                        'id', 'document_type', 'path', 'status'
                    ],
                    'tenants' => [
                        '*' => [
                            'user_id', 'name', 'email', 'phone', 'gender', 'is_verified',
                            'membership' => ['id', 'status', 'payment_deadline'],
                            'identity_document' => ['id', 'type', 'document_number', 'path', 'status', 'is_verified'],
                            'signed_contract' => ['id', 'path', 'status']
                        ]
                    ]
                ]
            ]);

        // Assert specific returned values
        $this->assertEquals($this->apartment->id, $response->json('data.apartment.id'));
        $this->assertEquals($this->owner->id, $response->json('data.owner.id'));
        $this->assertEquals($this->ownerIdDoc->id, $response->json('data.owner_identity_document.id'));
        $this->assertEquals($this->aptDoc->id, $response->json('data.ownership_document.id'));

        $tenants = $response->json('data.tenants');
        $this->assertCount(1, $tenants);
        $this->assertEquals($this->tenant->id, $tenants[0]['user_id']);
        $this->assertEquals($this->tenantIdDoc->id, $tenants[0]['identity_document']['id']);
        $this->assertEquals($this->tenantContract->id, $tenants[0]['signed_contract']['id']);
    }

    /**
     * Test non-admin cannot access moderation details.
     */
    public function test_non_admin_cannot_access_moderation_details()
    {
        // Tenant
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson("/api/admin/apartments/{$this->apartment->id}/moderation-details");
        $response->assertStatus(403);

        // Owner
        $responseOwner = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/admin/apartments/{$this->apartment->id}/moderation-details");
        $responseOwner->assertStatus(403);
    }

    /**
     * Test search page (index) returns only open status apartments for tenants.
     */
    public function test_tenant_search_only_returns_open_apartments()
    {
        // Create a closed apartment matching gender
        $closedApartment = Apartment::create([
            'owner_id' => $this->owner->id,
            'price' => 1800.00,
            'insurance' => 400.00,
            'capacity' => 2,
            'male_count' => 0,
            'female_count' => 0,
            'gender_allowed' => 'male',
            'rooms_count' => 1,
            'beds_count' => 2,
            'status' => 'closed_uploading_contracts',
            'verification_status' => 'approved',
            'location' => new Point(30.0, 31.0),
        ]);

        // Create a draft apartment
        $draftApartment = Apartment::create([
            'owner_id' => $this->owner->id,
            'price' => 1500.00,
            'insurance' => 300.00,
            'capacity' => 2,
            'male_count' => 0,
            'female_count' => 0,
            'gender_allowed' => 'male',
            'rooms_count' => 1,
            'beds_count' => 2,
            'status' => 'draft',
            'verification_status' => 'approved',
            'location' => new Point(30.0, 31.0),
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/apartments');

        $response->assertStatus(200);

        // Assert only the open apartment is returned
        $data = $response->json('data');
        $returnedIds = collect($data)->pluck('id')->toArray();

        $this->assertContains($this->apartment->id, $returnedIds);
        $this->assertNotContains($closedApartment->id, $returnedIds);
        $this->assertNotContains($draftApartment->id, $returnedIds);
    }
}
