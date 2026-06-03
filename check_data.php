<?php
require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== DATA RECEIVED CHECK ===\n\n";

// Check users
$userCount = DB::table('users')->count();
echo "Total Users: $userCount\n";

if ($userCount > 0) {
    echo "\nUser Details:\n";
    $users = DB::table('users')->select('id', 'email', 'phone', 'is_verified', 'created_at')->limit(5)->get();
    foreach ($users as $user) {
        echo "  - Email: {$user->email}\n";
        echo "    Phone: {$user->phone}\n";
        echo "    Verified: " . ($user->is_verified ? 'YES' : 'NO') . "\n";
        echo "    Created: {$user->created_at}\n\n";
    }
}

// Check rental profiles
$rentalCount = DB::table('rental_profiles')->count();
echo "Total Rental Profiles: $rentalCount\n";

if ($rentalCount > 0) {
    echo "\nRental Profile Details:\n";
    $rentals = DB::table('rental_profiles')->select('id', 'user_id', 'type', 'created_at')->limit(5)->get();
    foreach ($rentals as $rental) {
        echo "  - Type: {$rental->type}\n";
        echo "    User ID: {$rental->user_id}\n";
        echo "    Created: {$rental->created_at}\n\n";
    }
}

// Check student details
$studentCount = DB::table('student_details')->count();
echo "Total Student Details: $studentCount\n";

if ($studentCount > 0) {
    echo "\nStudent Details:\n";
    $students = DB::table('student_details')->select('id', 'rental_profile_id', 'university', 'faculty', 'budget_min', 'budget_max', 'preferred_location')->limit(5)->get();
    foreach ($students as $student) {
        echo "  - University: {$student->university}\n";
        echo "    Faculty: {$student->faculty}\n";
        echo "    Budget: {$student->budget_min} - {$student->budget_max}\n";
        echo "    Location: {$student->preferred_location}\n\n";
    }
}

// Check identity verifications
$verifyCount = DB::table('identity_verifications')->count();
echo "Total Identity Verifications: $verifyCount\n";

if ($verifyCount > 0) {
    echo "\nIdentity Verification Details:\n";
    $verifications = DB::table('identity_verifications')->select('id', 'user_id', 'overall_status', 'validation_passed', 'face_match_passed', 'liveness_passed', 'submitted_at')->limit(5)->get();
    foreach ($verifications as $v) {
        echo "  - Status: {$v->overall_status}\n";
        echo "    Validation: " . ($v->validation_passed ? 'PASS' : 'FAIL') . "\n";
        echo "    Face Match: " . ($v->face_match_passed ? 'PASS' : 'FAIL') . "\n";
        echo "    Liveness: " . ($v->liveness_passed ? 'PASS' : 'FAIL') . "\n";
        echo "    Submitted: {$v->submitted_at}\n\n";
    }
}

// Check apartments
$apartmentCount = DB::table('apartments')->count();
echo "Total Apartments: $apartmentCount\n";

if ($apartmentCount > 0) {
    echo "\nApartment Details:\n";
    $apartments = DB::table('apartments')->select('id', 'name', 'price', 'city', 'status', 'created_at')->limit(5)->get();
    foreach ($apartments as $apt) {
        echo "  - Name: {$apt->name}\n";
        echo "    Price: {$apt->price}\n";
        echo "    City: {$apt->city}\n";
        echo "    Status: {$apt->status}\n";
        echo "    Created: {$apt->created_at}\n\n";
    }
}

echo "=== END OF DATA CHECK ===\n";
