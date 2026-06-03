<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\UserProfile;
use App\Models\RentalProfile;
use App\Models\StudentDetail;
use App\Models\EmployeeDetail;
use App\Models\SponsorProfile;
use App\Models\Apartment;
use App\Models\ApartmentPhoto;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use MatanYadaev\EloquentSpatial\Objects\Point;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed universities first so their campus coordinates are available for the
        // recommender's distance feature and for the onboarding university picker.
        $this->call(EgyptianUniversitiesSeeder::class);
        $this->call(TestDataSeeder::class);
    }

    private function _disabled(): void
    {
        // 1. Seed Roles
        $roles = [];
        foreach (['admin', 'rental', 'owner', 'sponsor'] as $roleName) {
            $roles[$roleName] = Role::firstOrCreate(['role' => $roleName]);
        }

        // 2. Helper to create users with profiles
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
                'age' => 25,
                'country' => 'Egypt',
                'city' => 'Cairo',
            ]);

            return $user;
        };

        // Seed users
        $admin = $createUser('admin@example.com', '+201000000001', 'Admin User', 'admin');
        $owner = $createUser('owner@example.com', '+201000000002', 'Owner User', 'owner');
        $tenant1 = $createUser('tenant1@example.com', '+201000000003', 'John Doe Tenant', 'rental', 'male');
        $tenant2 = $createUser('tenant2@example.com', '+201000000004', 'Jane Doe Tenant', 'rental', 'female');
        $sponsor = $createUser('sponsor@example.com', '+201000000005', 'Sponsor User', 'sponsor');

        // Create specific profiles where required
        // Rental profile for Tenant 1 (student)
        $rentalProfile1 = RentalProfile::create([
            'user_id' => $tenant1->id,
            'type' => 'student',
        ]);
        StudentDetail::create([
            'rental_profile_id' => $rentalProfile1->id,
            'university' => 'Cairo University',
            'faculty' => 'Engineering',
        ]);

        // Rental profile for Tenant 2 (employee)
        $rentalProfile2 = RentalProfile::create([
            'user_id' => $tenant2->id,
            'type' => 'employee',
        ]);
        EmployeeDetail::create([
            'rental_profile_id' => $rentalProfile2->id,
            'company' => 'Tech Corp',
            'job_title' => 'Software Engineer',
        ]);

        // Sponsor profile
        SponsorProfile::create([
            'user_id' => $sponsor->id,
            'company_name' => 'Sukoon Sponsors Inc.',
            'company_details' => 'Supporting student housing and youth accommodation projects.',
            'target_audience' => 'Students and young professionals',
        ]);

        // 3. Seed 3 Apartments with capacity always 2
        // Apartment 1: Male or any
        $apt1 = Apartment::create([
            'owner_id' => $owner->id,
            'price' => 5000.00,
            'insurance' => 1000.00,
            'capacity' => 2,
            'male_count' => 0,
            'female_count' => 0,
            'gender_allowed' => 'any',
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
        ApartmentPhoto::create([
            'apartment_id' => $apt1->id,
            'path' => 'apartment_photos/seeded_apt1.jpg',
        ]);

        // Apartment 2: Male
        $apt2 = Apartment::create([
            'owner_id' => $owner->id,
            'price' => 4500.00,
            'insurance' => 1500.00,
            'capacity' => 2,
            'male_count' => 0,
            'female_count' => 0,
            'gender_allowed' => 'male',
            'rooms_count' => 3,
            'beds_count' => 4,
            'has_ac' => false,
            'has_water' => true,
            'has_gas' => true,
            'is_furnished' => true,
            'location' => new Point(30.0700, 31.2800),
            'status' => 'open',
            'verification_status' => 'approved',
            'rent_duration' => 6,
        ]);
        ApartmentPhoto::create([
            'apartment_id' => $apt2->id,
            'path' => 'apartment_photos/seeded_apt2.jpg',
        ]);

        // Apartment 3: Female
        $apt3 = Apartment::create([
            'owner_id' => $owner->id,
            'price' => 6000.00,
            'insurance' => 2000.00,
            'capacity' => 2,
            'male_count' => 0,
            'female_count' => 0,
            'gender_allowed' => 'female',
            'rooms_count' => 2,
            'beds_count' => 2,
            'has_ac' => true,
            'has_water' => true,
            'has_gas' => false,
            'is_furnished' => false,
            'location' => new Point(30.0131, 31.2089),
            'status' => 'open',
            'verification_status' => 'approved',
            'rent_duration' => 12,
        ]);
        ApartmentPhoto::create([
            'apartment_id' => $apt3->id,
            'path' => 'apartment_photos/seeded_apt3.jpg',
        ]);
    }
}
