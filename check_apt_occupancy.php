<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$aptId = $argv[1] ?? '019e7b53-5edf-708a-976b-44b132df3259';
$apt = App\Models\Apartment::find($aptId);
if (!$apt) {
    echo "apartment not found\n";
    exit(1);
}
$members = App\Models\ApartmentMember::where('apartment_id', $aptId)
    ->whereIn('membership_status', ['pending', 'active'])
    ->with('user:id,gender,email')
    ->get();
echo json_encode([
    'apartment' => [
        'capacity' => $apt->capacity,
        'male_count' => $apt->male_count,
        'female_count' => $apt->female_count,
        'status' => $apt->status,
        'computed_occupancy' => $apt->male_count + $apt->female_count,
        'free_spots' => $apt->capacity - ($apt->male_count + $apt->female_count),
    ],
    'members' => $members->map(fn ($m) => [
        'status' => $m->membership_status,
        'gender' => $m->gender_snapshot,
        'email' => $m->user->email ?? null,
    ]),
], JSON_PRETTY_PRINT) . "\n";
