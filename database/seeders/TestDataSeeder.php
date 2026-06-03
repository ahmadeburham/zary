<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // ── 1. Roles ──────────────────────────────────────────────────────────
        DB::table('roles')->insertOrIgnore([
            ['id' => Str::uuid()->toString(), 'role' => 'admin'],
            ['id' => Str::uuid()->toString(), 'role' => 'owner'],
            ['id' => Str::uuid()->toString(), 'role' => 'rental'],
            ['id' => Str::uuid()->toString(), 'role' => 'sponsor'],
        ]);

        $adminRoleId  = DB::table('roles')->where('role', 'admin')->value('id');
        $ownerRoleId  = DB::table('roles')->where('role', 'owner')->value('id');
        $rentalRoleId = DB::table('roles')->where('role', 'rental')->value('id');

        // ── 2. Admin user ─────────────────────────────────────────────────────
        $adminId = Str::uuid()->toString();
        DB::table('users')->insertOrIgnore([
            'id'                   => $adminId,
            'email'                => 'admin@sukoon.test',
            'phone'                => '+201000000001',
            'gender'               => 'male',
            'password'             => Hash::make('password123'),
            'google_id'            => null,
            'facebook_id'          => null,
            'fcm_token'            => null,
            'name'                 => null,
            'is_verified'          => 1,
            'is_profile_completed' => 1,
            'onboarding_screen'    => 3,
            'has_paid_platform_fee'=> 0,
            'payout_info'          => null,
            'payout_type'          => null,
            'payout_number'        => null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
        // Re-read in case insertOrIgnore skipped (already exists)
        $adminId = DB::table('users')->where('email', 'admin@sukoon.test')->value('id');
        DB::table('role_user')->insertOrIgnore(['role_id' => $adminRoleId, 'user_id' => $adminId]);
        DB::table('user_profiles')->insertOrIgnore([
            'id' => Str::uuid()->toString(), 'user_id' => $adminId,
            'first_name' => 'Admin', 'last_name' => 'User', 'age' => 30,
            'country' => 'Egypt', 'city' => 'Cairo',
        ]);

        // ── 2b. Second Admin user ────────────────────────────────────────────
        $admin2Id = Str::uuid()->toString();
        DB::table('users')->insertOrIgnore([
            'id'                   => $admin2Id,
            'email'                => 'admin2@sukoon.test',
            'phone'                => '+201000000004',
            'gender'               => 'male',
            'password'             => Hash::make('password123'),
            'google_id'            => null,
            'facebook_id'          => null,
            'fcm_token'            => null,
            'name'                 => null,
            'is_verified'          => 1,
            'is_profile_completed' => 1,
            'onboarding_screen'    => 3,
            'has_paid_platform_fee'=> 0,
            'payout_info'          => null,
            'payout_type'          => null,
            'payout_number'        => null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
        $admin2Id = DB::table('users')->where('email', 'admin2@sukoon.test')->value('id');
        DB::table('role_user')->insertOrIgnore(['role_id' => $adminRoleId, 'user_id' => $admin2Id]);
        DB::table('user_profiles')->insertOrIgnore([
            'id' => Str::uuid()->toString(), 'user_id' => $admin2Id,
            'first_name' => 'Second', 'last_name' => 'Admin', 'age' => 28,
            'country' => 'Egypt', 'city' => 'Cairo',
        ]);

        // ── 3. Owner user ─────────────────────────────────────────────────────
        DB::table('users')->insertOrIgnore([
            'id'                   => Str::uuid()->toString(),
            'email'                => 'owner@sukoon.test',
            'phone'                => '+201000000002',
            'gender'               => 'male',
            'password'             => Hash::make('password123'),
            'is_verified'          => 1,
            'is_profile_completed' => 1,
            'onboarding_screen'    => 3,
            'has_paid_platform_fee'=> 0,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
        $ownerId = DB::table('users')->where('email', 'owner@sukoon.test')->value('id');
        DB::table('role_user')->insertOrIgnore(['role_id' => $ownerRoleId, 'user_id' => $ownerId]);
        DB::table('user_profiles')->insertOrIgnore([
            'id' => Str::uuid()->toString(), 'user_id' => $ownerId,
            'first_name' => 'Owner', 'last_name' => 'Test', 'age' => 35,
            'country' => 'Egypt', 'city' => 'Cairo',
        ]);

        // ── 4. Tenant user ────────────────────────────────────────────────────
        DB::table('users')->insertOrIgnore([
            'id'                   => Str::uuid()->toString(),
            'email'                => 'tenant1@sukoon.test',
            'phone'                => '+201000000003',
            'gender'               => 'male',
            'password'             => Hash::make('password123'),
            'is_verified'          => 1,
            'is_profile_completed' => 1,
            'onboarding_screen'    => 3,
            'has_paid_platform_fee'=> 0,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
        $tenantId = DB::table('users')->where('email', 'tenant1@sukoon.test')->value('id');
        DB::table('role_user')->insertOrIgnore(['role_id' => $rentalRoleId, 'user_id' => $tenantId]);
        DB::table('user_profiles')->insertOrIgnore([
            'id' => Str::uuid()->toString(), 'user_id' => $tenantId,
            'first_name' => 'Tenant', 'last_name' => 'One', 'age' => 25,
            'country' => 'Egypt', 'city' => 'Cairo',
        ]);

        $this->command->info('✅ Seeder complete.');
        $this->command->info('   admin@sukoon.test  | password: password123');
        $this->command->info('   admin2@sukoon.test | password: password123');
        $this->command->info('   owner@sukoon.test  | password: password123');
        $this->command->info('   tenant1@sukoon.test | password: password123');
    }
}
