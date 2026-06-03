<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$aptId = '019e7b53-5edf-708a-976b-44b132df3259';
$apt = App\Models\Apartment::find($aptId);
$json = $apt->toArray();
echo "DB male_count={$apt->male_count} capacity={$apt->capacity}\n";
echo "API keys: " . implode(',', array_keys($json)) . "\n";
echo "has occupants_count=" . (array_key_exists('occupants_count', $json) ? 'yes' : 'no') . "\n";
echo json_encode(['male_count' => $json['male_count'] ?? null, 'female_count' => $json['female_count'] ?? null, 'capacity' => $json['capacity'] ?? null]) . "\n";
