<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\UserProfile;
use App\Models\RentalProfile;
use App\Models\StudentDetail;
use App\Models\IdentityDocument;
use App\Models\Apartment;
use App\Models\ApartmentMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Illuminate\Support\Facades\DB;

class CapacityTestSeeder extends Seeder
{
    /**
     * Seed the application's database with capacity test case.
     */
    public function run(): void
    {
        // 1. Seed Roles if they don't exist
        $roles = [];
        foreach (['admin', 'rental', 'owner', 'sponsor'] as $roleName) {
            $roles[$roleName] = Role::firstOrCreate(['role' => $roleName]);
        }

        $emails = [
            'owner_test@example.com',
            'joined_tenant@example.com',
            'pending_tenant@example.com'
        ];

        // 2. Clean up previous seeded testing data for predictability
        $oldUsers = User::whereIn('email', $emails)->get();
        foreach ($oldUsers as $oldUser) {
            // Delete members
            ApartmentMember::where('user_id', $oldUser->id)->delete();
            // Delete contracts
            DB::table('tenants_contracts')->where('user_id', $oldUser->id)->delete();
            // Delete notifications
            DB::table('notifications')->where('user_id', $oldUser->id)->delete();
            // Delete identity documents
            IdentityDocument::where('user_id', $oldUser->id)->delete();
            // Delete rental profile
            $rp = RentalProfile::where('user_id', $oldUser->id)->first();
            if ($rp) {
                StudentDetail::where('rental_profile_id', $rp->id)->delete();
                $rp->delete();
            }
            // Delete user profile
            UserProfile::where('user_id', $oldUser->id)->delete();
            
            // Delete apartments owned by owner_test
            if ($oldUser->email === 'owner_test@example.com') {
                $apts = Apartment::where('owner_id', $oldUser->id)->get();
                foreach ($apts as $apt) {
                    ApartmentMember::where('apartment_id', $apt->id)->delete();
                    DB::table('apartment_photos')->where('apartment_id', $apt->id)->delete();
                    DB::table('apartment_documents')->where('apartment_id', $apt->id)->delete();
                    $apt->delete();
                }
            }

            // Finally delete the user
            $oldUser->roles()->detach();
            $oldUser->delete();
        }

        // 3. Helper to create user with profile
        $createUser = function($email, $phone, $name, $roleName, $gender = 'male') use ($roles) {
            $user = User::create([
                'email' => $email,
                'phone' => $phone,
                'password' => Hash::make('password123'),
                'gender' => $gender,
                'is_verified' => true,
                'is_profile_completed' => true,
                'onboarding_screen' => 3,
            ]);

            // Attach role
            $user->roles()->attach($roles[$roleName]->id);

            // Create UserProfile
            $names = explode(' ', $name, 3);
            UserProfile::create([
                'user_id' => $user->id,
                'first_name' => $names[0] ?? 'First',
                'middle_name' => $names[1] ?? null,
                'last_name' => $names[2] ?? ($names[1] ?? 'Last'),
                'age' => 24,
                'country' => 'Egypt',
                'city' => 'Cairo',
            ]);

            return $user;
        };

        // 4. Create Owner and Tenants
        $owner = $createUser('owner_test@example.com', '+201099999990', 'Test Owner', 'owner');
        $joinedTenant = $createUser('joined_tenant@example.com', '+201099999991', 'Joined Tenant', 'rental', 'male');
        $pendingTenant = $createUser('pending_tenant@example.com', '+201099999992', 'Pending Tenant', 'rental', 'male');

        // Create rental profiles & identity docs for both tenants
        foreach ([$joinedTenant, $pendingTenant] as $tenant) {
            $rp = RentalProfile::create([
                'user_id' => $tenant->id,
                'type' => 'student',
            ]);
            StudentDetail::create([
                'rental_profile_id' => $rp->id,
                'university' => 'Cairo University',
                'faculty' => 'Engineering',
            ]);
            IdentityDocument::create([
                'user_id' => $tenant->id,
                'type' => 'national_id',
                'document_number' => 'nid_' . $tenant->id,
                'path' => 'identity_documents/seeded.jpg',
                'status' => 'approved',
                'is_verified' => true,
            ]);
        }

        // 5. Create the Apartment with capacity = 2, male_count = 1, status = open, verification_status = approved
        $apartment = Apartment::create([
            'owner_id' => $owner->id,
            'price' => 3000.00,
            'insurance' => 1000.00,
            'capacity' => 2,
            'male_count' => 1,
            'female_count' => 0,
            'gender_allowed' => 'male',
            'rooms_count' => 2,
            'beds_count' => 2,
            'has_ac' => true,
            'has_water' => true,
            'has_gas' => true,
            'is_furnished' => true,
            'location' => new Point(30.0444, 31.2357), // Cairo coordinates
            'status' => 'open',
            'verification_status' => 'approved',
            'rent_duration' => 12,
        ]);

        // Create DB record for the first member who has joined
        ApartmentMember::create([
            'apartment_id' => $apartment->id,
            'user_id' => $joinedTenant->id,
            'gender_snapshot' => 'male',
            'membership_status' => 'pending', // When someone joins, status is pending
        ]);

        $this->command->info("Test capacity environment seeded successfully!");
        $this->command->info("Apartment ID: " . $apartment->id);
        $this->command->info("Joined Tenant: joined_tenant@example.com (password123)");
        $this->command->info("Pending Tenant: pending_tenant@example.com (password123) <-- Use this to join the apartment!");
    }
}
