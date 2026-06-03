<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Apartment;
use App\Models\RentalProfile;
use App\Models\StudentDetail;
use App\Models\EmployeeDetail;
use App\Models\IdentityDocument;
use App\Models\TenantContract;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SukoonDemoSeeder extends Seeder
{
    // Egyptian Universities with GPS coordinates
    private $universities = [
        ['name' => 'Cairo University', 'city' => 'Giza', 'lat' => 30.0283, 'lng' => 31.2104, 'type' => 'public'],
        ['name' => 'Ain Shams University', 'city' => 'Cairo', 'lat' => 30.0738, 'lng' => 31.2834, 'type' => 'public'],
        ['name' => 'Alexandria University', 'city' => 'Alexandria', 'lat' => 31.2001, 'lng' => 29.9187, 'type' => 'public'],
        ['name' => 'Mansoura University', 'city' => 'Mansoura', 'lat' => 31.0409, 'lng' => 31.3785, 'type' => 'public'],
        ['name' => 'Tanta University', 'city' => 'Tanta', 'lat' => 30.7865, 'lng' => 31.0004, 'type' => 'public'],
        ['name' => 'Zagazig University', 'city' => 'Zagazig', 'lat' => 30.5765, 'lng' => 31.4779, 'type' => 'public'],
        ['name' => 'Helwan University', 'city' => 'Helwan', 'lat' => 29.8491, 'lng' => 31.3342, 'type' => 'public'],
        ['name' => 'Benha University', 'city' => 'Banha', 'lat' => 30.4656, 'lng' => 31.1848, 'type' => 'public'],
        ['name' => 'Kafr El Sheikh University', 'city' => 'Kafr El Sheikh', 'lat' => 31.1095, 'lng' => 30.9425, 'type' => 'public'],
        ['name' => 'Menoufia University', 'city' => 'Shebin El Koum', 'lat' => 30.5650, 'lng' => 31.0106, 'type' => 'public'],
        ['name' => 'Port Said University', 'city' => 'Port Said', 'lat' => 31.2565, 'lng' => 32.3010, 'type' => 'public'],
        ['name' => 'Suez Canal University', 'city' => 'Ismailia', 'lat' => 30.5965, 'lng' => 32.2715, 'type' => 'public'],
        ['name' => 'Assiut University', 'city' => 'Assiut', 'lat' => 27.1783, 'lng' => 31.1868, 'type' => 'public'],
        ['name' => 'German University in Cairo', 'city' => 'New Cairo', 'lat' => 30.0255, 'lng' => 31.4917, 'type' => 'private'],
        ['name' => 'British University in Egypt', 'city' => 'El Sherouk', 'lat' => 30.1650, 'lng' => 31.6000, 'type' => 'private'],
        ['name' => 'Misr International University', 'city' => '6th of October', 'lat' => 29.9365, 'lng' => 30.9200, 'type' => 'private'],
        ['name' => 'Modern Sciences and Arts University', 'city' => '6th of October', 'lat' => 29.9700, 'lng' => 30.9500, 'type' => 'private'],
        ['name' => 'Future University in Egypt', 'city' => 'New Cairo', 'lat' => 30.0200, 'lng' => 31.4500, 'type' => 'private'],
        ['name' => 'October 6 University', 'city' => '6th of October', 'lat' => 29.9667, 'lng' => 30.9333, 'type' => 'private'],
        ['name' => 'Cairo Institute of Technology', 'city' => 'New Cairo', 'lat' => 30.0100, 'lng' => 31.4200, 'type' => 'private'],
    ];

    // Faculties and categories
    private $faculties = [
        'Engineering' => ['category' => 'tech', 'majors' => ['Computer Engineering', 'Electrical Engineering', 'Mechanical Engineering', 'Civil Engineering', 'Architecture']],
        'Commerce' => ['category' => 'business', 'majors' => ['Accounting', 'Finance', 'Marketing', 'Business Administration', 'Economics']],
        'Medicine' => ['category' => 'medical', 'majors' => ['General Medicine', 'Dentistry', 'Pharmacy', 'Nursing', 'Physical Therapy']],
        'Science' => ['category' => 'science', 'majors' => ['Computer Science', 'Mathematics', 'Physics', 'Chemistry', 'Biology']],
        'Arts' => ['category' => 'arts', 'majors' => ['English Literature', 'Arabic Literature', 'History', 'Philosophy', 'Media Studies']],
        'Law' => ['category' => 'law', 'majors' => ['Public Law', 'Private Law', 'International Law']],
    ];

    // Cairo/Giza areas with coordinates for apartments
    private $cairoAreas = [
        ['name' => 'Zamalek', 'city' => 'Cairo', 'lat' => 30.0571, 'lng' => 31.2102],
        ['name' => 'Maadi', 'city' => 'Cairo', 'lat' => 29.9602, 'lng' => 31.2579],
        ['name' => 'Nasr City', 'city' => 'Cairo', 'lat' => 30.0566, 'lng' => 31.3300],
        ['name' => 'Heliopolis', 'city' => 'Cairo', 'lat' => 30.0886, 'lng' => 31.2844],
        ['name' => 'Downtown', 'city' => 'Cairo', 'lat' => 30.0444, 'lng' => 31.2357],
        ['name' => 'Garden City', 'city' => 'Cairo', 'lat' => 30.0419, 'lng' => 31.2254],
        ['name' => 'Dokki', 'city' => 'Giza', 'lat' => 30.0382, 'lng' => 31.2123],
        ['name' => 'Mohandessin', 'city' => 'Giza', 'lat' => 30.0511, 'lng' => 31.1996],
        ['name' => '6th of October', 'city' => 'Giza', 'lat' => 29.9658, 'lng' => 30.9398],
        ['name' => 'Sheikh Zayed', 'city' => 'Giza', 'lat' => 30.0495, 'lng' => 30.9763],
        ['name' => 'New Cairo', 'city' => 'Cairo', 'lat' => 30.0156, 'lng' => 31.4345],
        ['name' => 'El Rehab', 'city' => 'Cairo', 'lat' => 30.0594, 'lng' => 31.4918],
    ];

    private $alexandriaAreas = [
        ['name' => 'Gleem', 'city' => 'Alexandria', 'lat' => 31.2386, 'lng' => 29.9632],
        ['name' => 'Sidi Bishr', 'city' => 'Alexandria', 'lat' => 31.2556, 'lng' => 29.9833],
        ['name' => 'Smouha', 'city' => 'Alexandria', 'lat' => 31.2156, 'lng' => 29.9434],
        ['name' => 'Miami', 'city' => 'Alexandria', 'lat' => 31.2697, 'lng' => 30.0086],
        ['name' => 'Camp Caesar', 'city' => 'Alexandria', 'lat' => 31.2500, 'lng' => 29.9667],
    ];

    private $otherCities = [
        ['name' => 'Downtown', 'city' => 'Mansoura', 'lat' => 31.0409, 'lng' => 31.3785],
        ['name' => 'El Gomhoreya', 'city' => 'Tanta', 'lat' => 30.7865, 'lng' => 31.0004],
        ['name' => 'Downtown', 'city' => 'Port Said', 'lat' => 31.2565, 'lng' => 32.3010],
        ['name' => 'El Sharq', 'city' => 'Ismailia', 'lat' => 30.5965, 'lng' => 32.2715],
    ];

    // First names for users
    private $maleNames = ['Ahmed', 'Mohamed', 'Ali', 'Hassan', 'Hussein', 'Omar', 'Karim', 'Amr', 'Khaled', 'Mostafa', 'Mahmoud', 'Tarek', 'Youssef', 'Ibrahim', 'Abdelrahman', 'Yahya', 'Adham', 'Ziad', 'Omar', 'Yassin'];
    private $femaleNames = ['Fatima', 'Aisha', 'Maryam', 'Nour', 'Salma', 'Mariam', 'Yasmin', 'Habiba', 'Sara', 'Farah', 'Malak', 'Hana', 'Nada', 'Rana', 'Dana', 'Laila', 'Nourhan', 'Jana', 'Arwa', 'Hagar'];

    public function run(): void
    {
        $this->command->info('Creating demo data for Sukoon...');

        // Clear existing data
        $this->clearExistingData();

        // Create roles first
        $this->createRoles();

        // Create 50 owners
        $owners = $this->createOwners(50);

        // Create 200 apartments distributed among owners
        $this->createApartments(200, $owners);

        // Create 50 student tenants
        $this->createStudents(50);

        // Create some contracts
        $this->createContracts(30);

        $this->command->info('Demo data created successfully!');
        $this->command->info('Test accounts:');
        $this->command->info('  admin@sukoon.test / password123');
        $this->command->info('  owner1@sukoon.test / password123');
        $this->command->info('  tenant1@sukoon.test / password123');
    }

    private function createRoles(): void
    {
        $roles = ['admin', 'owner', 'rental', 'sponsor'];

        foreach ($roles as $role) {
            Role::firstOrCreate(['role' => $role]);
        }
    }

    private function clearExistingData(): void
    {
        TenantContract::truncate();
        StudentDetail::truncate();
        EmployeeDetail::truncate();
        RentalProfile::truncate();
        IdentityDocument::truncate();
        Apartment::truncate();
        
        // Delete all users except admin (admin will be created fresh)
        User::truncate();
    }

    private function createOwners(int $count): array
    {
        $owners = [];
        $cities = ['Cairo', 'Giza', 'Alexandria', 'Mansoura', 'Tanta'];

        for ($i = 1; $i <= $count; $i++) {
            $isMale = rand(0, 1) === 1;
            $firstName = $isMale ? $this->maleNames[array_rand($this->maleNames)] : $this->femaleNames[array_rand($this->femaleNames)];
            $lastName = $this->maleNames[array_rand($this->maleNames)];
            
            $owner = User::create([
                'email' => "owner{$i}@sukoon.test",
                'phone' => '+20' . rand(100000000, 999999999),
                'password' => Hash::make('password123'),
                'gender' => $isMale ? 'male' : 'female',
                'is_verified' => true,
                'created_at' => now()->subDays(rand(30, 365)),
            ]);
            $owner->roles()->attach(Role::where('role', 'owner')->first()->id);

            // Create user profile
            $owner->profile()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'age' => rand(35, 65),
                'city' => $cities[array_rand($cities)],
                'country' => 'Egypt',
            ]);

            $owners[] = $owner;
        }

        // Create default owner
        $defaultOwner = User::create([
            'email' => 'owner@sukoon.test',
            'phone' => '+201000000000',
            'password' => Hash::make('password123'),
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $defaultOwner->roles()->attach(Role::where('role', 'owner')->first()->id);
        $defaultOwner->profile()->create([
            'first_name' => 'Mohamed',
            'last_name' => 'Ali',
            'age' => 45,
            'city' => 'Cairo',
            'country' => 'Egypt',
        ]);
        $owners[] = $defaultOwner;

        return $owners;
    }

    private function createApartments(int $count, array $owners): void
    {
        $allAreas = array_merge($this->cairoAreas, $this->alexandriaAreas, $this->otherCities);
        
        $titles = [
            'Modern', 'Luxury', 'Cozy', 'Spacious', 'Elegant', 'Premium', 'Stylish', 'Contemporary',
            'Classic', 'Bright', 'Sunny', 'Quiet', 'Central', 'Newly Renovated', 'Furnished',
            'Semi-Furnished', 'Unfurnished', 'Studio', 'Duplex', 'Penthouse'
        ];
        
        $types = ['apartment', 'studio', 'duplex', 'penthouse'];
        
        for ($i = 1; $i <= $count; $i++) {
            $area = $allAreas[array_rand($allAreas)];
            $owner = $owners[array_rand($owners)];
            $type = $types[array_rand($types)];
            $title = $titles[array_rand($titles)] . ' ' . $type . ' in ' . $area['name'];
            
            // Add small random offset to coordinates for variety
            $lat = $area['lat'] + (rand(-100, 100) / 10000);
            $lng = $area['lng'] + (rand(-100, 100) / 10000);
            
            $bedrooms = $type === 'studio' ? 1 : rand(1, 4);
            $bathrooms = $bedrooms >= 2 ? rand(1, 2) : 1;
            $isFurnished = rand(0, 2) > 0; // 66% furnished
            $areaSqm = $bedrooms === 1 ? rand(50, 90) : ($bedrooms * rand(30, 45));
            
            // Price based on location and features
            $basePrice = $area['city'] === 'Cairo' ? 5000 : ($area['city'] === 'Alexandria' ? 4000 : 3000);
            $price = $basePrice + ($bedrooms * 1500) + ($isFurnished ? 1000 : 0) + rand(-500, 1500);
            $price = max(2000, $price);

            Apartment::create([
                'owner_id' => $owner->id,
                'price' => $price,
                'insurance' => $price * 0.5, // 50% of rent as insurance
                'capacity' => $bedrooms * 2, // 2 people per room
                'male_count' => 0,
                'female_count' => 0,
                'gender_allowed' => rand(0, 2) === 0 ? 'male' : (rand(0, 1) === 0 ? 'female' : 'any'),
                'rooms_count' => $bedrooms,
                'beds_count' => $bedrooms,
                'has_ac' => rand(0, 1) === 1,
                'has_water' => rand(0, 3) > 0, // 75% have water
                'has_gas' => rand(0, 3) > 0, // 75% have gas
                'is_furnished' => $isFurnished,
                'latitude' => $lat,
                'longitude' => $lng,
                'status' => rand(0, 5) > 0 ? 'open' : 'closed', // 80% available (open), 20% closed
                'verification_status' => rand(0, 3) > 0 ? 'approved' : 'pending', // 75% approved
                'created_at' => now()->subDays(rand(1, 90)),
            ]);
        }

        $this->command->info("Created {$count} apartments");
    }

    private function createStudents(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $isMale = rand(0, 1) === 1;
            $firstName = $isMale ? $this->maleNames[array_rand($this->maleNames)] : $this->femaleNames[array_rand($this->femaleNames)];
            $lastName = $this->maleNames[array_rand($this->maleNames)];
            
            $university = $this->universities[array_rand($this->universities)];
            $facultyKeys = array_keys($this->faculties);
            $facultyName = $facultyKeys[array_rand($facultyKeys)];
            $faculty = $this->faculties[$facultyName];
            $major = $faculty['majors'][array_rand($faculty['majors'])];
            
            $tenant = User::create([
                'email' => "tenant{$i}@sukoon.test",
                'phone' => '+20' . rand(100000000, 999999999),
                'password' => Hash::make('password123'),
                'gender' => $isMale ? 'male' : 'female',
                'is_verified' => true,
                'created_at' => now()->subDays(rand(1, 30)),
            ]);
            $tenant->roles()->attach(Role::where('role', 'rental')->first()->id);

            // User profile
            $tenant->profile()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'age' => rand(18, 26),
                'city' => $university['city'],
                'country' => 'Egypt',
            ]);

            // Rental profile
            $budgetMin = rand(2000, 4000);
            $budgetMax = $budgetMin + rand(1000, 4000);
            
            $rentalProfile = RentalProfile::create([
                'user_id' => $tenant->id,
                'type' => 'student',
            ]);
            $rentalProfile->refresh(); // Ensure UUID is loaded

            // Make sure the faculty exists in faculty_affinity_groups first to satisfy foreign key
            $fullFacultyName = 'Faculty of ' . $facultyName;
            $categoryMap = [
                'tech' => 'STEM_MEDICAL',
                'medical' => 'STEM_MEDICAL',
                'science' => 'STEM_MEDICAL',
                'business' => 'BUSINESS',
                'arts' => 'HUMANITIES',
                'law' => 'HUMANITIES',
            ];
            $affinityGroup = $categoryMap[$faculty['category']] ?? 'UNKNOWN';

            if (!DB::table('faculty_affinity_groups')->where('faculty_name', $fullFacultyName)->exists()) {
                DB::table('faculty_affinity_groups')->insert([
                    'faculty_name' => $fullFacultyName,
                    'affinity_group' => $affinityGroup
                ]);
            }

            // Map city to preferred_location enum value
            $allowedLocations = ['Cairo', 'Giza', 'Alexandria', 'Mansoura', 'Tanta', 'Port Said', 'Suez', 'Ismailia', 'Zagazig'];
            $preferredLocation = in_array($university['city'], $allowedLocations) ? $university['city'] : 'Other';

            // Student details with university location
            StudentDetail::create([
                'rental_profile_id' => $rentalProfile->id,
                'university' => $university['name'],
                'university_latitude' => $university['lat'],
                'university_longitude' => $university['lng'],
                'faculty' => $fullFacultyName,
                'budget_min' => $budgetMin,
                'budget_max' => $budgetMax,
                'preferred_location' => $preferredLocation,
                'prefers_furnished' => rand(0, 3) > 0,
                'major' => $major,
                'major_category' => $faculty['category'],
            ]);
        }

        // Create default tenant
        $defaultTenant = User::create([
            'email' => 'tenant@sukoon.test',
            'phone' => '+201111111111',
            'password' => Hash::make('password123'),
            'gender' => 'male',
            'is_verified' => true,
        ]);
        $defaultTenant->roles()->attach(Role::where('role', 'rental')->first()->id);
        $defaultTenant->profile()->create([
            'first_name' => 'Ahmed',
            'last_name' => 'Student',
            'age' => 22,
            'city' => 'Giza',
            'country' => 'Egypt',
        ]);
        
        $rentalProfile = RentalProfile::create([
            'user_id' => $defaultTenant->id,
            'type' => 'student',
        ]);
        $rentalProfile->refresh(); // Ensure UUID is loaded
        
        // Ensure Faculty of Engineering is in faculty_affinity_groups
        if (!DB::table('faculty_affinity_groups')->where('faculty_name', 'Faculty of Engineering')->exists()) {
            DB::table('faculty_affinity_groups')->insert([
                'faculty_name' => 'Faculty of Engineering',
                'affinity_group' => 'STEM_MEDICAL'
            ]);
        }

        StudentDetail::create([
            'rental_profile_id' => $rentalProfile->id,
            'university' => 'Cairo University',
            'university_latitude' => 30.0283,
            'university_longitude' => 31.2104,
            'faculty' => 'Faculty of Engineering',
            'budget_min' => 3000,
            'budget_max' => 6000,
            'preferred_location' => 'Giza',
            'prefers_furnished' => true,
            'major' => 'Computer Engineering',
            'major_category' => 'tech',
        ]);

        $this->command->info("Created {$count} student tenants");
    }

    private function getNearbyAreas(string $city): string
    {
        $nearby = [
            'Cairo' => 'Zamalek, Maadi, Nasr City, Heliopolis',
            'Giza' => 'Dokki, Mohandessin, 6th of October, Sheikh Zayed',
            'Alexandria' => 'Gleem, Sidi Bishr, Smouha, Miami',
            'Mansoura' => 'Downtown, Gehan',
            'Tanta' => 'El Gomhoreya, Downtown',
            'Port Said' => 'Downtown, Port Fouad',
            'Ismailia' => 'Downtown, El Sharq',
            'New Cairo' => 'Rehab, Fifth Settlement',
            '6th of October' => 'City Center, Industrial Area',
        ];
        
        return $nearby[$city] ?? 'Downtown';
    }

    private function createContracts(int $count): void
    {
        $tenants = User::where('role', 'rental')->take($count)->get();
        $apartments = Apartment::where('status', 'available')->take($count)->get();
        
        foreach ($tenants as $index => $tenant) {
            if (!isset($apartments[$index])) break;
            
            $apartment = $apartments[$index];
            
            TenantContract::create([
                'apartment_id' => $apartment->id,
                'tenant_id' => $tenant->id,
                'owner_id' => $apartment->owner_id,
                'status' => rand(0, 2) === 0 ? 'pending' : (rand(0, 1) === 0 ? 'accepted' : 'active'),
                'rent_amount' => $apartment->price,
                'duration_months' => rand(6, 12),
                'move_in_date' => now()->addDays(rand(7, 60)),
                'created_at' => now()->subDays(rand(1, 14)),
            ]);
            
            // Mark apartment as rented if contract is active
            if (rand(0, 1) === 0) {
                $apartment->update(['status' => 'rented']);
            }
        }

        $this->command->info("Created {$count} contracts");
    }
}
