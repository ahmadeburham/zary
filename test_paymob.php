<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Testing Paymob Connection...\n\n";

// Check environment variables
echo "Environment Variables:\n";
echo "  PAYMOB_API_KEY: " . (env('PAYMOB_API_KEY') ? 'SET (' . strlen(env('PAYMOB_API_KEY')) . ' chars)' : 'NOT SET') . "\n";
echo "  PAYMOB_INTEGRATION_ID: " . (env('PAYMOB_INTEGRATION_ID') ?: 'NOT SET') . "\n";
echo "  PAYMOB_IFRAME_ID: " . (env('PAYMOB_IFRAME_ID') ?: 'NOT SET') . "\n";
echo "  PAYMOB_BASE_URL: " . (env('PAYMOB_BASE_URL') ?: 'NOT SET') . "\n\n";

// Test Paymob Service
$service = new \App\Services\PaymobService();
echo "Testing getAuthToken():\n";
$token = $service->getAuthToken();

if ($token) {
    echo "  SUCCESS! Token obtained: " . substr($token, 0, 20) . "...\n";
    echo "  Token length: " . strlen($token) . "\n";
} else {
    echo "  FAILED - Token is null\n";
    echo "  Check storage/logs/laravel.log for details\n";
}
