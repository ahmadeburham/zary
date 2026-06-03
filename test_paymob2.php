<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Testing Paymob Connection...\n\n";

// Check environment variables
echo "Environment Variables:\n";
$apiKey = env('PAYMOB_API_KEY');
echo "  PAYMOB_API_KEY: " . ($apiKey ? 'SET (' . strlen($apiKey) . ' chars)' : 'NOT SET') . "\n";
echo "  PAYMOB_INTEGRATION_ID: " . (env('PAYMOB_INTEGRATION_ID') ?: 'NOT SET') . "\n";
echo "  PAYMOB_IFRAME_ID: " . (env('PAYMOB_IFRAME_ID') ?: 'NOT SET') . "\n";
echo "  PAYMOB_BASE_URL: " . (env('PAYMOB_BASE_URL') ?: 'NOT SET') . "\n\n";

// Direct HTTP test
$baseUrl = env('PAYMOB_BASE_URL', 'https://accept.paymob.com');
$url = $baseUrl . '/api/auth/tokens';
echo "Testing direct HTTP POST to: $url\n";

try {
    $response = \Illuminate\Support\Facades\Http::post($url, [
        'api_key' => $apiKey
    ]);

    echo "Response Status: " . $response->status() . "\n";
    echo "Response Body:\n";
    print_r($response->json());
    echo "\n";

    if ($response->successful()) {
        $token = $response->json()['token'] ?? null;
        echo "Token obtained: " . ($token ? 'YES' : 'NO') . "\n";
    } else {
        echo "Request failed!\n";
    }
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
