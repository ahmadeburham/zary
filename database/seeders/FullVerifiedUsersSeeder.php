<?php

namespace Database\Seeders;

use App\Models\IdentityDocument;
use App\Models\IdentityVerification;
use App\Models\RentalProfile;
use App\Models\Role;
use App\Models\SponsorProfile;
use App\Models\StudentDetail;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Upserts three fully verified test users (admin, owner, tenant) with complete related data.
 * Run: php artisan db:seed --class=FullVerifiedUsersSeeder
 */
class FullVerifiedUsersSeeder extends Seeder
{
    private const PASSWORD = 'password123';

    public function run(): void
    {
        $this->ensureRoles();

        $admin = $this->seedAdmin();
        $owner = $this->seedOwner();
        $tenant = $this->seedTenant();

        $this->command?->info('Full verified users ready (password: ' . self::PASSWORD . '):');
        $this->command?->info("  Admin:  admin@sukoon.test  (roles: {$admin->roles->pluck('role')->join(', ')})");
        $this->command?->info("  Owner:  owner@sukoon.test  (roles: {$owner->roles->pluck('role')->join(', ')})");
        $this->command?->info("  Tenant: tenant1@sukoon.test (roles: {$tenant->roles->pluck('role')->join(', ')})");
    }

    private function ensureRoles(): void
    {
        foreach (['admin', 'owner', 'rental', 'sponsor'] as $role) {
            Role::firstOrCreate(['role' => $role]);
        }

        $faculty = 'Faculty of Engineering';
        if (!DB::table('faculty_affinity_groups')->where('faculty_name', $faculty)->exists()) {
            DB::table('faculty_affinity_groups')->insert([
                'faculty_name' => $faculty,
                'affinity_group' => 'STEM_MEDICAL',
            ]);
        }
    }

    private function seedAdmin(): User
    {
        $user = $this->upsertUser([
            'email' => 'admin@sukoon.test',
            'phone' => '+201000000001',
            'gender' => 'male',
            'payout_type' => null,
            'payout_number' => null,
        ]);

        $this->syncRoles($user, ['admin']);

        $this->upsertProfile($user, [
            'first_name' => 'Sukoon',
            'middle_name' => 'Platform',
            'last_name' => 'Admin',
            'age' => 32,
            'country' => 'Egypt',
            'city' => 'Cairo',
            'id_number' => '29801011234567',
            'birth_date' => '1992-03-15',
            'address' => '15 Tahrir Square, Downtown, Cairo',
            'profession' => 'Platform Administrator',
            'religion' => 'Muslim',
            'marital_status' => 'single',
            'id_issue_date' => '2018-06-01',
            'id_expiry_date' => '2028-06-01',
        ]);

        SponsorProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => 'Sukoon Housing',
                'company_details' => 'Student housing verification and rental platform.',
                'target_audience' => 'University students in Greater Cairo',
            ]
        );

        $this->upsertIdentityDocument($user, '29801011234567');
        $this->upsertIdentityVerification($user, 'Sukoon Platform Admin', '29801011234567', 'male');

        return $user->fresh(['roles', 'profile', 'sponsorProfile']);
    }

    private function seedOwner(): User
    {
        $user = $this->upsertUser([
            'email' => 'owner@sukoon.test',
            'phone' => '+201000000002',
            'gender' => 'male',
            'payout_type' => 'wallet',
            'payout_number' => '01012345678',
        ]);

        $this->syncRoles($user, ['owner']);

        $this->upsertProfile($user, [
            'first_name' => 'Mohamed',
            'middle_name' => 'Ali',
            'last_name' => 'Hassan',
            'age' => 45,
            'country' => 'Egypt',
            'city' => 'Giza',
            'id_number' => '28506151234567',
            'birth_date' => '1979-08-20',
            'address' => '12 Dokki Street, Giza',
            'profession' => 'Property Owner',
            'religion' => 'Muslim',
            'marital_status' => 'married',
            'id_issue_date' => '2015-01-10',
            'id_expiry_date' => '2030-01-10',
        ]);

        $this->upsertIdentityDocument($user, '28506151234567');
        $this->upsertIdentityVerification($user, 'Mohamed Ali Hassan', '28506151234567', 'male');

        return $user->fresh(['roles', 'profile']);
    }

    private function seedTenant(): User
    {
        $user = $this->upsertUser([
            'email' => 'tenant1@sukoon.test',
            'phone' => '+201000000003',
            'gender' => 'male',
            'payout_type' => null,
            'payout_number' => null,
        ]);

        $this->syncRoles($user, ['rental']);

        $this->upsertProfile($user, [
            'first_name' => 'Ahmed',
            'middle_name' => 'Mahmoud',
            'last_name' => 'Ibrahim',
            'age' => 22,
            'country' => 'Egypt',
            'city' => 'Giza',
            'id_number' => '30305151234567',
            'birth_date' => '2003-05-15',
            'address' => 'Cairo University Campus, Giza',
            'profession' => 'Student',
            'religion' => 'Muslim',
            'marital_status' => 'single',
            'id_issue_date' => '2020-09-01',
            'id_expiry_date' => '2030-09-01',
        ]);

        $rentalProfile = RentalProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['type' => 'student']
        );

        DB::table('student_details')->updateOrInsert(
            ['rental_profile_id' => $rentalProfile->id],
            [
                'university' => 'Cairo University',
                'university_latitude' => 30.0283,
                'university_longitude' => 31.2104,
                'faculty' => 'Faculty of Engineering',
                'major' => 'Computer Engineering',
                'major_category' => 'tech',
                'budget_min' => 3000,
                'budget_max' => 8000,
                'preferred_location' => 'Giza',
                'prefers_furnished' => true,
            ]
        );

        $this->upsertIdentityDocument($user, '30305151234567');
        $this->upsertIdentityVerification($user, 'Ahmed Mahmoud Ibrahim', '30305151234567', 'male');

        return $user->fresh(['roles', 'profile', 'rentalProfile']);
    }

    private function upsertUser(array $attrs): User
    {
        $user = User::updateOrCreate(
            ['email' => $attrs['email']],
            [
                'phone' => $attrs['phone'],
                'password' => Hash::make(self::PASSWORD),
                'gender' => $attrs['gender'],
                'is_verified' => true,
                'liveness_passed' => true,
                'face_match_passed' => true,
                'is_profile_completed' => true,
                'onboarding_screen' => 3,
                'has_paid_platform_fee' => false,
                'payout_type' => $attrs['payout_type'] ?? null,
                'payout_number' => $attrs['payout_number'] ?? null,
                'fcm_token' => null,
            ]
        );

        return $user;
    }

    private function syncRoles(User $user, array $roleNames): void
    {
        $roleIds = collect($roleNames)
            ->map(fn (string $name) => Role::where('role', $name)->value('id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $user->roles()->sync($roleIds);
    }

    private function upsertProfile(User $user, array $data): void
    {
        UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            $data
        );
    }

    private function upsertIdentityDocument(User $user, string $idNumber): void
    {
        IdentityDocument::updateOrCreate(
            ['user_id' => $user->id],
            [
                'type' => 'national_id',
                'document_number' => $idNumber,
                'path' => 'seeded/identity/' . $user->id . '/national_id.pdf',
                'is_verified' => true,
                'status' => 'approved',
                'rejection_reason' => null,
            ]
        );
    }

    private function upsertIdentityVerification(
        User $user,
        string $fullName,
        string $idNumber,
        string $gender
    ): void {
        $requestId = 'seed_' . Str::slug($user->email);

        IdentityVerification::where('user_id', $user->id)->delete();

        IdentityVerification::create([
                'user_id' => $user->id,
                'request_id' => $requestId,
                'overall_status' => 'completed',
                'validation_passed' => true,
                'face_match_passed' => true,
                'liveness_passed' => true,
                'ocr_front_passed' => true,
                'ocr_back_passed' => true,
                'id_number' => $idNumber,
                'extracted_name' => $fullName,
                'birth_date' => $user->profile?->birth_date ?? '1990-01-01',
                'address' => $user->profile?->address ?? 'Cairo, Egypt',
                'gender' => $gender,
                'ml_result_json' => [
                    'source' => 'FullVerifiedUsersSeeder',
                    'sections' => [
                        'validation' => ['id_valid' => true],
                        'face_match' => ['passed' => true, 'score' => 0.96],
                        'liveness' => ['passed' => true, 'status' => 'ok'],
                        'ocr_front' => [
                            'id_number' => $idNumber,
                            'name' => $fullName,
                        ],
                        'ocr_back' => ['gender' => $gender],
                    ],
                ],
                'submitted_at' => now()->subDay(),
                'completed_at' => now(),
        ]);
    }
}
