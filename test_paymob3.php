<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$apiKey = env('PAYMOB_API_KEY');

// Test both URLs
$urls = [
    'https://accept.paymob.com/api/auth/tokens',
    'https://accept-alpha.paymob.com/api/auth/tokens',
];

foreach ($urls as $url) {
    echo "\n========================================\n";
    echo "Testing: $url\n";
    echo "========================================\n";

    try {
        $response = \Illuminate\Support\Facades\Http::timeout(10)->post($url, [
            'api_key' => $apiKey
        ]);

        echo "Status: " . $response->status() . "\n";
        echo "Body: " . json_encode($response->json()) . "\n";

        if ($response->successful()) {
            echo "✅ SUCCESS!\n";
        } else {
            echo "❌ Failed with status " . $response->status() . "\n";
        }
    } catch (Exception $e) {
        echo "❌ Exception: " . $e->getMessage() . "\n";
    }
}
