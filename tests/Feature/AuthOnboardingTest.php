<?php

namespace Tests\Feature;

use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure roles exist in DB
        foreach (['rental', 'owner', 'sponsor'] as $role) {
            Role::firstOrCreate(['role' => $role]);
        }
    }

    /**
     * Test registration creates user, assigns role, generates and logs OTP.
     */
    public function test_user_can_register_and_receives_otp()
    {
        $payload = [
            'phone' => '1234567890',
            'email' => 'tenant@example.com',
            'password' => 'password123',
            'gender' => 'male',
            'role' => 'rental',
        ];

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'user' => ['id', 'phone', 'email', 'gender', 'is_verified', 'onboarding_screen'],
                    'token',
                ]
            ]);

        $this->assertDatabaseHas('users', [
            'phone' => '1234567890',
            'email' => 'tenant@example.com',
            'is_verified' => 0,
            'onboarding_screen' => 1,
        ]);

        $user = User::where('email', 'tenant@example.com')->first();
        $this->assertTrue($user->hasRole('rental'));

        // Check if OTP was created in database
        $this->assertDatabaseHas('otp', [
            'user_id' => $user->id,
            'type' => 'registration',
        ]);
    }

    /**
     * Test verification of OTP.
     */
    public function test_user_can_verify_otp()
    {
        $payload = [
            'phone' => '0987654321',
            'password' => 'password123',
            'gender' => 'female',
            'role' => 'owner',
        ];

        $regResponse = $this->postJson('/api/auth/register', $payload);
        $user = User::where('phone', '0987654321')->first();
        $otp = Otp::where('user_id', $user->id)->first();

        // Verify with wrong OTP code first
        $verifyResponse = $this->postJson('/api/auth/verify-otp', [
            'phone' => '0987654321',
            'code' => '000000',
        ]);
        $verifyResponse->assertStatus(400);

        // Verify with correct code
        $verifyResponse2 = $this->postJson('/api/auth/verify-otp', [
            'phone' => '0987654321',
            'code' => $otp->code,
        ]);
        $verifyResponse2->assertStatus(200);

        $user->refresh();
        $this->assertTrue($user->is_verified);
    }

    /**
     * Test logins via email and phone.
     */
    public function test_user_can_login_via_email_or_phone()
    {
        $user = User::create([
            'phone' => '1112223334',
            'email' => 'testlogin@example.com',
            'password' => bcrypt('password123'),
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $user->roles()->attach(Role::where('role', 'rental')->first()->id);

        // Test login via email
        $responseEmail = $this->postJson('/api/auth/login', [
            'login' => 'testlogin@example.com',
            'password' => 'password123',
        ]);
        $responseEmail->assertStatus(200)->assertJsonStructure(['data' => ['token']]);

        // Test login via phone
        $responsePhone = $this->postJson('/api/auth/login', [
            'login' => '1112223334',
            'password' => 'password123',
        ]);
        $responsePhone->assertStatus(200)->assertJsonStructure(['data' => ['token']]);
    }

    /**
     * Test social login.
     */
    public function test_social_login_and_registration()
    {
        // 1. Social login registers a new user if provider_id not found (bypasses OTP)
        $payload = [
            'provider' => 'google',
            'provider_id' => 'google-uid-123',
            'email' => 'googlesocial@example.com',
            'name' => 'Google User',
            'role' => 'owner',
        ];

        $response = $this->postJson('/api/auth/social-login', $payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'email' => 'googlesocial@example.com',
            'google_id' => 'google-uid-123',
            'is_verified' => 1,
        ]);

        // 2. Subsequent social login logins existing user
        $response2 = $this->postJson('/api/auth/social-login', [
            'provider' => 'google',
            'provider_id' => 'google-uid-123',
            'email' => 'googlesocial@example.com',
        ]);
        $response2->assertStatus(200);
    }

    /**
     * Test multi-step onboarding for Rental role.
     */
    public function test_rental_onboarding_steps()
    {
        // Register & verify rental
        $user = User::create([
            'phone' => '5556667777',
            'email' => 'rentalflow@example.com',
            'password' => bcrypt('password123'),
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $user->roles()->attach(Role::where('role', 'rental')->first()->id);

        $token = $user->createToken('auth_token')->plainTextToken;

        // Try user profile (step 3) before rental profile (step 2) - should fail sequence check
        $this->withToken($token)
            ->postJson('/api/auth/onboarding/user-profile', [
                'first_name' => 'Alice',
                'last_name' => 'Smith',
                'age' => 22,
                'country' => 'Egypt',
                'city' => 'Cairo',
            ])->assertStatus(422);

        // Complete step 2: rental profile (student)
        $this->withToken($token)
            ->postJson('/api/auth/onboarding/rental-profile', [
                'type' => 'student',
                'university' => 'Cairo University',
                'faculty' => 'Engineering',
            ])->assertStatus(200);

        $this->assertDatabaseHas('rental_profiles', ['user_id' => $user->id, 'type' => 'student']);
        $this->assertDatabaseHas('student_details', ['university' => 'Cairo University', 'faculty' => 'Engineering']);
        $this->assertEquals(2, $user->fresh()->onboarding_screen);

        // Complete step 3: user profile
        $this->withToken($token)
            ->postJson('/api/auth/onboarding/user-profile', [
                'first_name' => 'Alice',
                'last_name' => 'Smith',
                'age' => 22,
                'country' => 'Egypt',
                'city' => 'Cairo',
            ])->assertStatus(200);

        $user->refresh();
        $this->assertEquals(3, $user->onboarding_screen);
        $this->assertTrue($user->is_profile_completed);
    }

    /**
     * Test multi-step onboarding for Sponsor role.
     */
    public function test_sponsor_onboarding_steps()
    {
        $user = User::create([
            'phone' => '8889990000',
            'email' => 'sponsorflow@example.com',
            'password' => bcrypt('password123'),
            'gender' => 'female',
            'is_verified' => true,
        ]);
        $user->roles()->attach(Role::where('role', 'sponsor')->first()->id);

        $token = $user->createToken('auth_token')->plainTextToken;

        // Try sponsor details (step 3) before user profile (step 2) - should fail sequence check
        $this->withToken($token)
            ->postJson('/api/auth/onboarding/sponsor-profile', [
                'company_name' => 'Sukoon Ads Inc',
                'company_details' => 'Premium advertising company.',
            ])->assertStatus(422);

        // Complete step 2: User profile
        $this->withToken($token)
            ->postJson('/api/auth/onboarding/user-profile', [
                'first_name' => 'Sarah',
                'last_name' => 'Sponsor',
                'age' => 30,
                'country' => 'Egypt',
                'city' => 'Alexandria',
            ])->assertStatus(200);

        // Complete step 3: Sponsor company details
        $this->withToken($token)
            ->postJson('/api/auth/onboarding/sponsor-profile', [
                'company_name' => 'Sukoon Ads Inc',
                'company_details' => 'Premium advertising company.',
                'target_audience' => 'Students and young professionals',
            ])->assertStatus(200);

        $user->refresh();
        $this->assertEquals(3, $user->onboarding_screen);
        $this->assertTrue($user->is_profile_completed);
        $this->assertDatabaseHas('sponsor_profiles', [
            'user_id' => $user->id,
            'company_name' => 'Sukoon Ads Inc',
        ]);
    }

    /**
     * Test cached profile response and invalidation on update.
     */
    public function test_profile_retrieval_caching()
    {
        $user = User::create([
            'email' => 'cachetest@example.com',
            'password' => bcrypt('password123'),
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $user->roles()->attach(Role::where('role', 'owner')->first()->id);

        $token = $user->createToken('auth_token')->plainTextToken;

        // Warm up cache via /me endpoint
        $response1 = $this->withToken($token)->getJson('/api/auth/me');
        $response1->assertStatus(200);

        // Verify key exists in Cache
        $this->assertTrue(Cache::has("auth_user_profile_{$user->id}"));

        // Complete step 2: User profile (which must invalidate the cache)
        $this->withToken($token)->postJson('/api/auth/onboarding/user-profile', [
            'first_name' => 'Cache',
            'last_name' => 'Owner',
            'age' => 35,
            'country' => 'Egypt',
            'city' => 'Cairo',
        ])->assertStatus(200);

        // Verify cache was invalidated
        $this->assertFalse(Cache::has("auth_user_profile_{$user->id}"));
    }

    /**
     * Test idempotency key prevents double submissions.
     */
    public function test_idempotency_prevents_double_submissions()
    {
        $user = User::create([
            'email' => 'idemp@example.com',
            'password' => bcrypt('password123'),
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $user->roles()->attach(Role::where('role', 'owner')->first()->id);

        $token = $user->createToken('auth_token')->plainTextToken;

        $idempotencyKey = 'key-' . uniqid();

        // Step 2 profile details request
        $payload = [
            'first_name' => 'Idemp',
            'last_name' => 'User',
            'age' => 28,
            'country' => 'Egypt',
            'city' => 'Cairo',
        ];

        // Send first request with key header
        $response1 = $this->withToken($token)
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/auth/onboarding/user-profile', $payload);

        $response1->assertStatus(200);

        // Send second request immediately with same key header
        $response2 = $this->withToken($token)
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/auth/onboarding/user-profile', $payload);

        // Must return the cached successful response, which matches status 200
        $response2->assertStatus(200);

        // The database record status should be 'completed'
        $this->assertDatabaseHas('idempotency_keys', [
            'key' => $idempotencyKey,
            'status' => 'completed',
        ]);
    }

    /**
     * Test token refresh endpoint.
     */
    public function test_user_can_refresh_token()
    {
        $user = User::create([
            'email' => 'refreshtest@example.com',
            'password' => bcrypt('password123'),
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $user->roles()->attach(Role::where('role', 'owner')->first()->id);

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/auth/token/refresh');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'token'
                ]
            ]);

        $newToken = $response->json('data.token');
        $this->assertNotEquals($token, $newToken);

        // Clear resolved authentication state for the next request in the same test
        app('auth')->forgetGuards();

        // Assert the old token is invalidated (subsequent request with old token should fail)
        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);

        // Clear resolved authentication state again
        app('auth')->forgetGuards();

        // Assert the new token works
        $this->withToken($newToken)
            ->getJson('/api/auth/me')
            ->assertStatus(200);
    }

    /**
     * Test token invalidation endpoint.
     */
    public function test_user_can_invalidate_token()
    {
        $user = User::create([
            'email' => 'invalidatetest@example.com',
            'password' => bcrypt('password123'),
            'gender' => 'female',
            'is_verified' => true,
        ]);
        $user->roles()->attach(Role::where('role', 'owner')->first()->id);

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/auth/token/invalidate');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Token invalidated successfully.'
            ]);

        // Clear resolved authentication state
        app('auth')->forgetGuards();

        // Old token should be rejected now
        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    /**
     * Test forget password and reset password flow.
     */
    public function test_forgot_and_reset_password()
    {
        $user = User::create([
            'email' => 'resetpasswordtest@example.com',
            'phone' => '07771112233',
            'password' => bcrypt('oldpassword123'),
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $user->roles()->attach(Role::where('role', 'owner')->first()->id);

        // Initiate forgot password flow
        $response = $this->postJson('/api/auth/forgot-password', [
            'login' => 'resetpasswordtest@example.com',
        ]);

        $response->assertStatus(200);

        // Assert OTP was generated and saved
        $this->assertDatabaseHas('otp', [
            'user_id' => $user->id,
            'type' => 'password_reset',
        ]);

        $otp = Otp::where('user_id', $user->id)->where('type', 'password_reset')->first();

        // Try resetting with wrong OTP code - should fail
        $this->postJson('/api/auth/reset-password', [
            'login' => 'resetpasswordtest@example.com',
            'code' => '000000',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(422);

        // Reset with correct code
        $this->postJson('/api/auth/reset-password', [
            'login' => 'resetpasswordtest@example.com',
            'code' => $otp->code,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(200);

        // Verify password was updated by logging in with new password
        $this->postJson('/api/auth/login', [
            'login' => 'resetpasswordtest@example.com',
            'password' => 'newpassword123',
        ])->assertStatus(200);
    }

    /**
     * Test authenticated change password flow.
     */
    public function test_change_password()
    {
        $user = User::create([
            'email' => 'changepasswordtest@example.com',
            'password' => bcrypt('currentpassword123'),
            'gender' => 'female',
            'is_verified' => true,
        ]);
        $user->roles()->attach(Role::where('role', 'owner')->first()->id);

        $token = $user->createToken('auth_token')->plainTextToken;

        // Try changing password with wrong current password - should fail
        $this->withToken($token)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'wrongpassword123',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])->assertStatus(422);

        // Change password successfully
        $this->withToken($token)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'currentpassword123',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])->assertStatus(200);

        // Clear resolved guard instances for authenticating again
        app('auth')->forgetGuards();

        // Verify login works with new password
        $this->postJson('/api/auth/login', [
            'login' => 'changepasswordtest@example.com',
            'password' => 'newpassword123',
        ])->assertStatus(200);
    }
}
