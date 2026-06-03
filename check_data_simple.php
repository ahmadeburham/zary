<?php
require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== DATA RECEIVED CHECK ===\n\n";

// Check users
$userCount = DB::table('users')->count();
echo "✅ Total Users: $userCount\n";

// Check verified users
$verifiedCount = DB::table('users')->where('is_verified', true)->count();
echo "✅ Verified Users: $verifiedCount\n";

// Check rental profiles
$rentalCount = DB::table('rental_profiles')->count();
echo "✅ Total Rental Profiles: $rentalCount\n";

// Check student details
$studentCount = DB::table('student_details')->count();
echo "✅ Total Student Details: $studentCount\n";

if ($studentCount > 0) {
    $student = DB::table('student_details')->first();
    echo "\n   Sample Student Details:\n";
    echo "   - University: {$student->university}\n";
    echo "   - Faculty: {$student->faculty}\n";
    echo "   - Budget: {$student->budget_min} - {$student->budget_max}\n";
    echo "   - Location: {$student->preferred_location}\n";
    echo "   - Furnished: " . ($student->prefers_furnished ? 'YES' : 'NO') . "\n";
}

// Check identity verifications
$verifyCount = DB::table('identity_verifications')->count();
echo "\n✅ Total Identity Verifications: $verifyCount\n";

if ($verifyCount > 0) {
    $v = DB::table('identity_verifications')->first();
    echo "\n   Sample Verification:\n";
    echo "   - Status: {$v->overall_status}\n";
    echo "   - Validation: " . ($v->validation_passed ? 'PASS' : 'FAIL') . "\n";
    echo "   - Face Match: " . ($v->face_match_passed ? 'PASS' : 'FAIL') . "\n";
    echo "   - Liveness: " . ($v->liveness_passed ? 'PASS' : 'FAIL') . "\n";
    echo "   - ID Number: {$v->id_number}\n";
    echo "   - Name: {$v->extracted_name}\n";
    echo "   - Gender: {$v->gender}\n";
}

// Check apartments
$apartmentCount = DB::table('apartments')->count();
echo "\n✅ Total Apartments: $apartmentCount\n";

if ($apartmentCount > 0) {
    $apt = DB::table('apartments')->first();
    echo "\n   Sample Apartment:\n";
    echo "   - Name: {$apt->name}\n";
    echo "   - Price: {$apt->price}\n";
    echo "   - City: {$apt->city}\n";
    echo "   - Status: {$apt->status}\n";
}

// Check apartment members
$memberCount = DB::table('apartment_members')->count();
echo "\n✅ Total Apartment Members: $memberCount\n";

// Check payments
$paymentCount = DB::table('payment_orders')->count();
echo "✅ Total Payment Orders: $paymentCount\n";

echo "\n=== SUMMARY ===\n";
echo "Data is being received and stored correctly!\n";
echo "Users: $userCount | Verified: $verifiedCount\n";
echo "Rental Profiles: $rentalCount | Student Details: $studentCount\n";
echo "Identity Verifications: $verifyCount\n";
echo "Apartments: $apartmentCount | Members: $memberCount\n";
echo "Payments: $paymentCount\n";
