<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\ApartmentMember;
use App\Models\IdentityDocument;
use App\Models\PaymentOrder;
use App\Models\RefundRequest;
use App\Models\RentCycle;
use App\Models\Role;
use App\Models\TenantContract;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymobService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use MatanYadaev\EloquentSpatial\Objects\Point;

class PaymentCycleTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $owner;
    protected User $tenant1;
    protected User $tenant2;
    protected Apartment $apt;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        // Create roles
        foreach (['rental', 'owner', 'admin'] as $roleName) {
            Role::firstOrCreate(['role' => $roleName]);
        }

        // Create Users
        $this->admin = User::factory()->create(['is_verified' => true]);
        $this->admin->roles()->attach(Role::where('role', 'admin')->first()->id);

        $this->owner = User::factory()->create([
            'is_verified' => true,
            'payout_info' => 'Owner Bank Account Info',
        ]);
        $this->owner->roles()->attach(Role::where('role', 'owner')->first()->id);

        $this->tenant1 = User::factory()->create([
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $this->tenant1->roles()->attach(Role::where('role', 'rental')->first()->id);

        $this->tenant2 = User::factory()->create([
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $this->tenant2->roles()->attach(Role::where('role', 'rental')->first()->id);

        // Create an approved identity document for both tenants to satisfy the verifiedDocsCount check
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

        // Create 2-capacity apartment
        $this->apt = Apartment::create([
            'owner_id' => $this->owner->id,
            'price' => 4000.00,
            'insurance' => 1000.00,
            'capacity' => 2,
            'male_count' => 0,
            'female_count' => 0,
            'gender_allowed' => 'male',
            'rooms_count' => 2,
            'beds_count' => 2,
            'status' => 'open',
            'verification_status' => 'approved',
            'location' => new Point(30.051234, 31.234567),
        ]);
    }

    /**
     * Test Location coordinate fuzzing and owner details privacy.
     */
    public function test_apartment_location_privacy_and_owner_details_hiding()
    {
        // 1. Authenticated non-tenant/non-member view
        $response = $this->actingAs($this->tenant1, 'sanctum')
            ->getJson("/api/apartments/{$this->apt->id}");
        $response->assertStatus(200);
        $data = $response->json()['data'];

        $this->assertArrayNotHasKey('owner', $data);
        $this->assertTrue($data['location_is_blurred']);
        $this->assertNotEquals(30.051234, $data['latitude']);
        $this->assertNotEquals(31.234567, $data['longitude']);

        // 2. Owner view (exact)
        $responseOwner = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/apartments/{$this->apt->id}");
        $dataOwner = $responseOwner->json()['data'];
        $this->assertFalse($dataOwner['location_is_blurred']);
        $this->assertEquals(30.051234, $dataOwner['latitude']);
        $this->assertEquals(31.234567, $dataOwner['longitude']);
    }

    /**
     * Test complete payment lifecycle when contracts and docs are verified.
     */
    public function test_complete_payment_flow_on_verification()
    {
        // Mock PaymobService
        $this->mock(PaymobService::class, function ($mock) {
            $mock->shouldReceive('getAuthToken')->andReturn('fake_token');
            $mock->shouldReceive('createOrder')->andReturnUsing(function ($token, $amountCents, $merchantOrderId) {
                return crc32($merchantOrderId);
            });
            $mock->shouldReceive('createPaymentKey')->andReturnUsing(function ($token, $paymobOrderId, $amountCents, $billingData) {
                return 'fake_payment_key_' . $paymobOrderId;
            });
            $mock->shouldReceive('getPaymentUrl')->andReturnUsing(function ($paymentKey) {
                return 'https://fake-paymob-url.com/' . $paymentKey;
            });
            $mock->shouldReceive('verifyHmac')->andReturn(true);
        });

        // Tenants join
        $this->actingAs($this->tenant1, 'sanctum')->postJson("/api/apartments/{$this->apt->id}/join")->assertStatus(200);
        $this->actingAs($this->tenant2, 'sanctum')->postJson("/api/apartments/{$this->apt->id}/join")->assertStatus(200);

        // Upload contracts
        $c1 = TenantContract::create([
            'user_id' => $this->tenant1->id,
            'apartment_id' => $this->apt->id,
            'path' => 'contracts/c1.pdf',
            'type' => 'lease',
            'status' => 'pending',
        ]);
        $c2 = TenantContract::create([
            'user_id' => $this->tenant2->id,
            'apartment_id' => $this->apt->id,
            'path' => 'contracts/c2.pdf',
            'type' => 'lease',
            'status' => 'pending',
        ]);

        // Verify first tenant's contract
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/tenant-contracts/{$c1->id}/verify")
            ->assertStatus(200);

        // Second contract verification will trigger payment flow
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/tenant-contracts/{$c2->id}/verify")
            ->assertStatus(200);

        // Assert RentCycle created in pending_payment
        $cycle = RentCycle::where('apartment_id', $this->apt->id)->first();
        $this->assertNotNull($cycle);
        $this->assertEquals('pending_payment', $cycle->status);

        // Assert PaymentOrders created
        $orders = PaymentOrder::where('rent_cycle_id', $cycle->id)->get();
        $this->assertCount(2, $orders);

        $order1 = $orders->where('user_id', $this->tenant1->id)->first();
        $order2 = $orders->where('user_id', $this->tenant2->id)->first();

        // Rent share: 4000/2 = 2000 EGP = 200000 cents
        // Insurance share: 1000/2 = 500 EGP = 50000 cents
        // Platform profit share: 2000/2 = 1000 EGP = 100000 cents
        // Total = 350000 cents
        $this->assertEquals(350000, $order1->amount_cents);
        $this->assertEquals('pending', $order1->status);

        // Simulate successful paymob callback for Tenant 1
        $callbackData = [
            'hmac' => 'fake_hmac',
            'obj' => [
                'id' => 'tx_999111',
                'success' => true,
                'amount_cents' => 350000,
                'currency' => 'EGP',
                'order' => [
                    'id' => (int) $order1->paymob_order_id
                ],
                'created_at' => now()->toIso8601String(),
            ]
        ];

        $responseWebhook = $this->postJson('/api/payments/webhook/paymob?hmac=fake_hmac', $callbackData);
        $responseWebhook->assertStatus(200);

        $this->assertEquals('paid', $order1->fresh()->status);
        $this->assertEquals('active', ApartmentMember::where('apartment_id', $this->apt->id)->where('user_id', $this->tenant1->id)->value('membership_status'));
        // Tenant 2 still pending
        $this->assertEquals('pending', ApartmentMember::where('apartment_id', $this->apt->id)->where('user_id', $this->tenant2->id)->value('membership_status'));

        // Simulate callback for Tenant 2
        $callbackData2 = $callbackData;
        $callbackData2['obj']['order']['id'] = (int) $order2->paymob_order_id;
        $callbackData2['obj']['id'] = 'tx_999222';

        $responseWebhook2 = $this->postJson('/api/payments/webhook/paymob?hmac=fake_hmac', $callbackData2);
        $responseWebhook2->assertStatus(200);

        $this->assertEquals('paid', $order2->fresh()->status);
        $this->assertEquals('active', ApartmentMember::where('apartment_id', $this->apt->id)->where('user_id', $this->tenant2->id)->value('membership_status'));

        // Rent cycle should now be active and apartment status rented
        $this->assertEquals('active', $cycle->fresh()->status);
        $this->assertEquals('rented', $this->apt->fresh()->status);

        // Platform & owner payouts should be recorded in transactions
        $this->assertDatabaseHas('transactions', [
            'apartment_id' => $this->apt->id,
            'type' => 'payout_owner',
            'amount_cents' => 400000, // 2000 + 2000
        ]);

        $this->assertDatabaseHas('transactions', [
            'apartment_id' => $this->apt->id,
            'type' => 'payout_platform',
            'amount_cents' => 200000, // 1000 + 1000
        ]);
    }

    /**
     * Test roommate checkout during payment phase cancels cycle and offers refunds.
     */
    public function test_roommate_checkout_cancels_payment_phase()
    {
        // Setup pending cycle & payment order
        $cycle = RentCycle::create([
            'apartment_id' => $this->apt->id,
            'cycle_number' => 1,
            'starts_at' => now(),
            'ends_at' => now()->addHours(24),
            'status' => 'pending_payment',
        ]);

        $order1 = PaymentOrder::create([
            'idempotency_key' => 'id_1',
            'rent_cycle_id' => $cycle->id,
            'apartment_id' => $this->apt->id,
            'user_id' => $this->tenant1->id,
            'amount_cents' => 350000,
            'breakdown' => [],
            'status' => 'paid', // Tenant 1 already paid
            'expires_at' => now()->addHours(24),
        ]);

        $order2 = PaymentOrder::create([
            'idempotency_key' => 'id_2',
            'rent_cycle_id' => $cycle->id,
            'apartment_id' => $this->apt->id,
            'user_id' => $this->tenant2->id,
            'amount_cents' => 350000,
            'breakdown' => [],
            'status' => 'pending', // Tenant 2 hasn't paid yet
            'expires_at' => now()->addHours(24),
        ]);

        ApartmentMember::create([
            'apartment_id' => $this->apt->id,
            'user_id' => $this->tenant1->id,
            'membership_status' => 'active',
            'gender_snapshot' => 'male',
        ]);

        ApartmentMember::create([
            'apartment_id' => $this->apt->id,
            'user_id' => $this->tenant2->id,
            'membership_status' => 'pending',
            'gender_snapshot' => 'male',
        ]);

        $this->apt->update(['status' => 'closed_uploading_contracts', 'male_count' => 2]);

        // Tenant 2 (who did not pay) leaves
        $responseLeave = $this->actingAs($this->tenant2, 'sanctum')
            ->postJson("/api/apartments/{$this->apt->id}/leave");
        $responseLeave->assertStatus(200);

        // Rent cycle should be cancelled, apartment opened
        $this->assertEquals('cancelled', $cycle->fresh()->status);
        $this->assertEquals('open', $this->apt->fresh()->status);
        $this->assertEquals(1, $this->apt->fresh()->male_count);

        // Tenant 1 requests refund
        $responseRefund = $this->actingAs($this->tenant1, 'sanctum')
            ->postJson('/api/tenant/refund');
        $responseRefund->assertStatus(201);

        $this->assertDatabaseHas('refund_requests', [
            'user_id' => $this->tenant1->id,
            'payment_order_id' => $order1->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Test console command kicks unpaid users after 24 hours.
     */
    public function test_deadline_checker_command_kicks_unpaid_tenants()
    {
        $cycle = RentCycle::create([
            'apartment_id' => $this->apt->id,
            'cycle_number' => 1,
            'starts_at' => now()->subHours(25),
            'ends_at' => now()->subHours(1), // expired
            'status' => 'pending_payment',
        ]);

        $order1 = PaymentOrder::create([
            'idempotency_key' => 'id_1',
            'rent_cycle_id' => $cycle->id,
            'apartment_id' => $this->apt->id,
            'user_id' => $this->tenant1->id,
            'amount_cents' => 350000,
            'breakdown' => [],
            'status' => 'paid', // Tenant 1 paid
            'expires_at' => now()->subHours(1),
        ]);

        $order2 = PaymentOrder::create([
            'idempotency_key' => 'id_2',
            'rent_cycle_id' => $cycle->id,
            'apartment_id' => $this->apt->id,
            'user_id' => $this->tenant2->id,
            'amount_cents' => 350000,
            'breakdown' => [],
            'status' => 'pending', // Tenant 2 did not pay
            'expires_at' => now()->subHours(1),
        ]);

        ApartmentMember::create([
            'apartment_id' => $this->apt->id,
            'user_id' => $this->tenant1->id,
            'membership_status' => 'active',
            'gender_snapshot' => 'male',
        ]);

        ApartmentMember::create([
            'apartment_id' => $this->apt->id,
            'user_id' => $this->tenant2->id,
            'membership_status' => 'pending',
            'gender_snapshot' => 'male',
        ]);

        $this->apt->update(['status' => 'closed_uploading_contracts', 'male_count' => 2]);

        // Run checking command
        Artisan::call('app:check-payment-deadlines');

        // Cycle should be cancelled, apartment open
        $this->assertEquals('cancelled', $cycle->fresh()->status);
        $this->assertEquals('open', $this->apt->fresh()->status);

        // Tenant 2 who did not pay must be kicked
        $this->assertEquals('cancelled', ApartmentMember::where('apartment_id', $this->apt->id)->where('user_id', $this->tenant2->id)->value('membership_status'));
        // Tenant 1 who paid must still be a member but deadline removed
        $member1 = ApartmentMember::where('apartment_id', $this->apt->id)->where('user_id', $this->tenant1->id)->first();
        $this->assertEquals('active', $member1->membership_status);
        $this->assertNull($member1->payment_deadline);
    }

    /**
     * Test roommate checkout during active cycle doesn't cancel cycle but opens apartment.
     */
    public function test_roommate_checkout_active_cycle_keeps_apartment_open_without_cancelling_cycle()
    {
        $cycle = RentCycle::create([
            'apartment_id' => $this->apt->id,
            'cycle_number' => 1,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'status' => 'active',
        ]);

        ApartmentMember::create([
            'apartment_id' => $this->apt->id,
            'user_id' => $this->tenant1->id,
            'membership_status' => 'active',
            'gender_snapshot' => 'male',
        ]);

        ApartmentMember::create([
            'apartment_id' => $this->apt->id,
            'user_id' => $this->tenant2->id,
            'membership_status' => 'active',
            'gender_snapshot' => 'male',
        ]);

        $this->apt->update(['status' => 'rented', 'male_count' => 2]);

        // Tenant 2 leaves the apartment
        $responseLeave = $this->actingAs($this->tenant2, 'sanctum')
            ->postJson("/api/apartments/{$this->apt->id}/leave");
        $responseLeave->assertStatus(200);

        // Cycle should remain active
        $this->assertEquals('active', $cycle->fresh()->status);
        // Apartment status should go back to open to let others join
        $this->assertEquals('open', $this->apt->fresh()->status);
        $this->assertEquals(1, $this->apt->fresh()->male_count);

        // Tenant 2 membership should be cancelled
        $this->assertEquals('cancelled', ApartmentMember::where('apartment_id', $this->apt->id)->where('user_id', $this->tenant2->id)->value('membership_status'));
    }

    /**
     * Test owner wallet payout via Paymob and platform fee flag logic.
     */
    public function test_owner_wallet_payout_and_platform_fee_flag_logic()
    {
        // 1. Setup owner with wallet payout method
        $this->owner->update([
            'payout_type' => 'wallet',
            'payout_number' => '01012345678',
        ]);

        // Mock PaymobService to expect payoutToWallet and other calls
        $this->mock(PaymobService::class, function ($mock) {
            $mock->shouldReceive('getAuthToken')->andReturn('fake_token');
            $mock->shouldReceive('createOrder')->andReturnUsing(function ($token, $amountCents, $merchantOrderId) {
                return crc32($merchantOrderId);
            });
            $mock->shouldReceive('createPaymentKey')->andReturnUsing(function ($token, $paymobOrderId, $amountCents, $billingData) {
                return 'fake_payment_key_' . $paymobOrderId;
            });
            $mock->shouldReceive('getPaymentUrl')->andReturnUsing(function ($paymentKey) {
                return 'https://fake-paymob-url.com/' . $paymentKey;
            });
            $mock->shouldReceive('verifyHmac')->andReturn(true);
            $mock->shouldReceive('payoutToWallet')
                ->once()
                ->with('01012345678', 400000)
                ->andReturn(['payout_id' => 'payout_tx_123', 'status' => 'SUCCESS']);
        });

        // 2. Tenants join
        $this->actingAs($this->tenant1, 'sanctum')->postJson("/api/apartments/{$this->apt->id}/join")->assertStatus(200);
        $this->actingAs($this->tenant2, 'sanctum')->postJson("/api/apartments/{$this->apt->id}/join")->assertStatus(200);

        // Assert tenants don't have has_paid_platform_fee flag set
        $this->assertFalse($this->tenant1->fresh()->has_paid_platform_fee);
        $this->assertFalse($this->tenant2->fresh()->has_paid_platform_fee);

        // Upload contracts
        $c1 = TenantContract::create([
            'user_id' => $this->tenant1->id,
            'apartment_id' => $this->apt->id,
            'path' => 'contracts/c1.pdf',
            'type' => 'lease',
            'status' => 'pending',
        ]);
        $c2 = TenantContract::create([
            'user_id' => $this->tenant2->id,
            'apartment_id' => $this->apt->id,
            'path' => 'contracts/c2.pdf',
            'type' => 'lease',
            'status' => 'pending',
        ]);

        // Verify contracts
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tenant-contracts/{$c1->id}/verify")->assertStatus(200);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/tenant-contracts/{$c2->id}/verify")->assertStatus(200);

        $cycle = RentCycle::where('apartment_id', $this->apt->id)->first();
        $orders = PaymentOrder::where('rent_cycle_id', $cycle->id)->get();
        $order1 = $orders->where('user_id', $this->tenant1->id)->first();
        $order2 = $orders->where('user_id', $this->tenant2->id)->first();

        // 350000 cents includes platform fee (100000 cents)
        $this->assertEquals(350000, $order1->amount_cents);

        // Webhook for tenant 1
        $callbackData = [
            'hmac' => 'fake_hmac',
            'obj' => [
                'id' => 'tx_999111',
                'success' => true,
                'amount_cents' => 350000,
                'currency' => 'EGP',
                'order' => ['id' => (int) $order1->paymob_order_id],
                'created_at' => now()->toIso8601String(),
            ]
        ];
        $this->postJson('/api/payments/webhook/paymob?hmac=fake_hmac', $callbackData)->assertStatus(200);

        // Tenant 1 should now have has_paid_platform_fee set to true
        $this->assertTrue($this->tenant1->fresh()->has_paid_platform_fee);

        // Webhook for tenant 2
        $callbackData2 = $callbackData;
        $callbackData2['obj']['order']['id'] = (int) $order2->paymob_order_id;
        $callbackData2['obj']['id'] = 'tx_999222';
        $this->postJson('/api/payments/webhook/paymob?hmac=fake_hmac', $callbackData2)->assertStatus(200);

        // Tenant 2 should also have has_paid_platform_fee set to true
        $this->assertTrue($this->tenant2->fresh()->has_paid_platform_fee);

        // Payout to owner should be logged as success in transactions
        $this->assertDatabaseHas('transactions', [
            'apartment_id' => $this->apt->id,
            'type' => 'payout_owner',
            'amount_cents' => 400000,
            'status' => 'success',
        ]);
    }
}
