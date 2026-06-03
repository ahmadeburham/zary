<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Http;

$base = 'https://bottom-backer-overlord.ngrok-free.dev';
$ahmadId = '019e6595-2611-728e-b00c-164b3a6fbcd6';
$targetApt = '019e65a5-e344-72cc-bb94-1897c6e85ebc';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$hmacSecret = env('PAYMOB_HMAC_SECRET');
$integrationId = env('PAYMOB_INTEGRATION_ID');

Cache::forget('paymob_auth_token');
$paymob = app(App\Services\PaymobService::class);

echo "=== ENV CHECK ===\n";
echo "INTEGRATION_ID: " . env('PAYMOB_INTEGRATION_ID') . "\n";
echo "IFRAME_ID:      " . env('PAYMOB_IFRAME_ID') . "\n";
echo "API_KEY start:  " . substr(env('PAYMOB_API_KEY'), 0, 30) . "...\n\n";

// Check local DB for order 534000897
$order = DB::table('payment_orders')->where('paymob_order_id', '534000897')->first();
echo "=== LOCAL DB STATUS ===\n";
if ($order) {
    echo "Status:  " . $order->status . "\n";
    echo "Paid at: " . ($order->paid_at ?? 'NULL — webhook NOT received yet') . "\n";
} else {
    echo "Order not found in local DB\n";
}

// Check membership
$member = DB::table('apartment_members')
    ->where('user_id', '019e6595-2611-728e-b00c-164b3a6fbcd6')
    ->first();
echo "\nMembership status: " . ($member->membership_status ?? 'NOT FOUND') . "\n";

// Check webhook URL is reachable
echo "\n=== WEBHOOK URL CHECK ===\n";
$webhookUrl = env('APP_URL') . '/api/payments/webhook/paymob';
echo "Webhook URL: {$webhookUrl}\n";
exit;

$hmacSecret    = env('PAYMOB_HMAC_SECRET');
$integrationId = env('PAYMOB_INTEGRATION_ID');

// ── Login as Ahmad ────────────────────────────────────────────────────────────
$login = Http::withHeaders(['Accept' => 'application/json'])
    ->post("{$base}/api/auth/login", ['login' => 'ahmad@gmail.com', 'password' => 'ahmad@gmail.com']);
$ahmadToken = $login->json()['data']['token'] ?? null;
echo "Login: " . ($ahmadToken ? 'OK' : 'FAILED') . "\n";
if (!$ahmadToken) exit;

// ── Join apartment ────────────────────────────────────────────────────────────
$aptId = '019e65a5-e344-72cc-bb94-1897c6e85ebc';
$join = Http::withHeaders(['Accept' => 'application/json', 'Authorization' => "Bearer {$ahmadToken}"])
    ->post("{$base}/api/apartments/{$aptId}/join");
$po = $join->json()['data']['payment_order'] ?? null;
if (!$po) { echo "Join failed: " . $join->body() . "\n"; exit; }

$paymobOrderId = $po['paymob_order_id'];
$amountCents   = $po['amount_cents'];
echo "Joined! Paymob Order ID: {$paymobOrderId} | Amount: " . ($amountCents/100) . " EGP\n\n";
$transactionId = rand(100000, 999999);
$createdAt     = now()->format('Y-m-d\TH:i:s.000000');

$obj = [
    'id'                     => $transactionId,
    'amount_cents'           => $amountCents,
    'created_at'             => $createdAt,
    'currency'               => 'EGP',
    'error_occured'          => false,
    'has_parent_transaction' => false,
    'integration_id'         => (int) $integrationId,
    'is_3d_secure'           => false,
    'is_auth'                => false,
    'is_capture'             => false,
    'is_refunded'            => false,
    'is_standalone_payment'  => true,
    'is_voided'              => false,
    'order'                  => ['id' => $paymobOrderId],
    'owner'                  => 1,
    'pending'                => false,
    'source_data'            => ['pan' => '1111', 'sub_type' => 'Visa', 'type' => 'card'],
    'success'                => true,
];

$concat  = $obj['amount_cents'] . $obj['created_at'] . $obj['currency'];
$concat .= $obj['error_occured'] ? 'true' : 'false';
$concat .= $obj['has_parent_transaction'] ? 'true' : 'false';
$concat .= $obj['id'] . $obj['integration_id'];
$concat .= $obj['is_3d_secure'] ? 'true' : 'false';
$concat .= $obj['is_auth'] ? 'true' : 'false';
$concat .= $obj['is_capture'] ? 'true' : 'false';
$concat .= $obj['is_refunded'] ? 'true' : 'false';
$concat .= $obj['is_standalone_payment'] ? 'true' : 'false';
$concat .= $obj['is_voided'] ? 'true' : 'false';
$concat .= $obj['order']['id'] . $obj['owner'];
$concat .= $obj['pending'] ? 'true' : 'false';
$concat .= $obj['source_data']['pan'] . $obj['source_data']['sub_type'] . $obj['source_data']['type'];
$concat .= $obj['success'] ? 'true' : 'false';

$hmac = hash_hmac('sha512', $concat, $hmacSecret);

echo "Firing webhook for Paymob order {$paymobOrderId}...\n";
$webhook = Http::withHeaders(['Accept' => 'application/json'])
    ->post("{$base}/api/payments/webhook/paymob?hmac={$hmac}", ['obj' => $obj]);
echo "Webhook Status: " . $webhook->status() . "\n";
echo "Response: " . $webhook->body() . "\n\n";

// Check local DB payment_orders for this paymob order id
$localOrder = DB::table('payment_orders')->where('paymob_order_id', $paymobOrderId)->first();
if ($localOrder) {
    echo "Local PaymentOrder status: " . $localOrder->status . "\n";
    echo "Paid at: " . ($localOrder->paid_at ?? 'NULL') . "\n";
} else {
    echo "NOTE: This order was a standalone test — not linked to a local PaymentOrder record.\n";
    echo "The webhook only processes orders that exist in the local payment_orders table.\n";
}
exit;

// Create order
$merchantKey = 'sukoon_live_test_' . time();
$orderResp = Http::post("https://accept.paymob.com/api/ecommerce/orders", [
    'auth_token' => $token,
    'delivery_needed' => 'false',
    'amount_cents' => '100000',
    'currency' => 'EGP',
    'merchant_order_id' => $merchantKey,
    'items' => []
]);
$orderId = $orderResp->json()['id'] ?? null;
echo "New Order ID: " . ($orderId ?? 'FAILED') . "\n";
echo "Merchant Key: {$merchantKey}\n";
echo "Created at: " . ($orderResp->json()['created_at'] ?? 'N/A') . "\n";
if (!$orderId) { echo $orderResp->body(); exit; }

// Payment key
$keyResp = Http::post("https://accept.paymob.com/api/acceptance/payment_keys", [
    'auth_token' => $token,
    'amount_cents' => 100000,
    'expiration' => 3600,
    'order_id' => $orderId,
    'billing_data' => [
        'first_name' => 'Ahmad', 'last_name' => 'Test',
        'email' => 'ahmad@gmail.com', 'phone_number' => '01000000000',
        'apartment' => 'NA', 'floor' => 'NA', 'street' => 'NA',
        'building' => 'NA', 'shipping_method' => 'NA',
        'postal_code' => 'NA', 'city' => 'NA', 'country' => 'EG', 'state' => 'NA'
    ],
    'currency' => 'EGP',
    'integration_id' => $integrationId,
]);
$pkey = $keyResp->json()['token'] ?? null;
echo "Payment Key: " . ($pkey ? 'OK' : 'FAILED') . "\n";
if (!$pkey) { echo $keyResp->body(); exit; }

$url = "https://accept.paymob.com/api/acceptance/iframes/{$iframeId}?payment_token={$pkey}";
echo "\n=== PAYMENT URL ===\n{$url}\n";
exit;

// ── STEP 1: Login as Ahmad ────────────────────────────────────────────────────
echo "=== STEP 1: Login as ahmad@gmail.com ===\n";
$login = Http::withHeaders(['Accept' => 'application/json'])
    ->post("{$base}/api/auth/login", ['login' => 'ahmad@gmail.com', 'password' => 'ahmad@gmail.com']);
$ahmadToken = $login->json()['data']['token'] ?? null;
echo "Status: " . $login->status() . " | token: " . ($ahmadToken ? 'OK' : 'FAILED') . "\n\n";
if (!$ahmadToken) { echo $login->body(); exit; }

// ── STEP 2: Join apartment ────────────────────────────────────────────────────
echo "=== STEP 2: Join apartment ===\n";
$aptId = '019e65a5-e344-72cc-bb94-1897c6e85ebc';
$join = Http::withHeaders(['Accept' => 'application/json', 'Authorization' => "Bearer {$ahmadToken}"])
    ->post("{$base}/api/apartments/{$aptId}/join");
echo "Status: " . $join->status() . "\n";
$joinData = $join->json()['data'] ?? [];
$paymentOrder = $joinData['payment_order'] ?? null;
if (!$paymentOrder) { echo "No payment order returned!\n" . $join->body(); exit; }

$paymobOrderId  = $paymentOrder['paymob_order_id'];
$amountCents    = $paymentOrder['amount_cents'];
$paymentOrderId = $paymentOrder['id'];
$paymentUrl     = $paymentOrder['payment_url'] ?? 'N/A';

echo "Payment Order ID (local): {$paymentOrderId}\n";
echo "Paymob Order ID:          {$paymobOrderId}\n";
echo "Amount (cents):           {$amountCents}\n";
echo "Payment URL:              " . substr($paymentUrl, 0, 80) . "...\n\n";

// ── STEP 3: Simulate Paymob webhook (successful payment) ─────────────────────
echo "=== STEP 3: Simulate Paymob webhook ===\n";

$createdAt     = now()->format('Y-m-d\TH:i:s.000000');
$transactionId = rand(100000, 999999);

$obj = [
    'id'                       => $transactionId,
    'amount_cents'             => $amountCents,
    'created_at'               => $createdAt,
    'currency'                 => 'EGP',
    'error_occured'            => false,
    'has_parent_transaction'   => false,
    'integration_id'           => (int) $integrationId,
    'is_3d_secure'             => false,
    'is_auth'                  => false,
    'is_capture'               => false,
    'is_refunded'              => false,
    'is_standalone_payment'    => true,
    'is_voided'                => false,
    'order'                    => ['id' => (int) $paymobOrderId],
    'owner'                    => 1,
    'pending'                  => false,
    'source_data'              => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
    'success'                  => true,
];

// Build HMAC string (Paymob standard field order)
$concat  = $obj['amount_cents'];
$concat .= $obj['created_at'];
$concat .= $obj['currency'];
$concat .= $obj['error_occured'] ? 'true' : 'false';
$concat .= $obj['has_parent_transaction'] ? 'true' : 'false';
$concat .= $obj['id'];
$concat .= $obj['integration_id'];
$concat .= $obj['is_3d_secure'] ? 'true' : 'false';
$concat .= $obj['is_auth'] ? 'true' : 'false';
$concat .= $obj['is_capture'] ? 'true' : 'false';
$concat .= $obj['is_refunded'] ? 'true' : 'false';
$concat .= $obj['is_standalone_payment'] ? 'true' : 'false';
$concat .= $obj['is_voided'] ? 'true' : 'false';
$concat .= $obj['order']['id'];
$concat .= $obj['owner'];
$concat .= $obj['pending'] ? 'true' : 'false';
$concat .= $obj['source_data']['pan'];
$concat .= $obj['source_data']['sub_type'];
$concat .= $obj['source_data']['type'];
$concat .= $obj['success'] ? 'true' : 'false';

$hmac = hash_hmac('sha512', $concat, $hmacSecret);
echo "Computed HMAC: " . substr($hmac, 0, 40) . "...\n";

$webhook = Http::withHeaders(['Accept' => 'application/json'])
    ->post("{$base}/api/payments/webhook/paymob?hmac={$hmac}", ['obj' => $obj]);

echo "Webhook Status: " . $webhook->status() . "\n";
echo "Webhook Response: " . $webhook->body() . "\n\n";

// ── STEP 4: Verify payment order is now 'paid' ────────────────────────────────
echo "=== STEP 4: Verify payment order status ===\n";
$order = DB::table('payment_orders')->where('id', $paymentOrderId)->first();
echo "Payment Order Status: " . ($order->status ?? 'NOT FOUND') . "\n";
echo "Paid At: " . ($order->paid_at ?? 'NULL') . "\n\n";

// Check membership status
$member = DB::table('apartment_members')
    ->where('user_id', $ahmadId)
    ->where('apartment_id', $aptId)
    ->first();
echo "Membership Status: " . ($member->membership_status ?? 'NOT FOUND') . "\n";

// Check rent cycle
$cycle = DB::table('rent_cycles')->where('apartment_id', $aptId)->first();
echo "Rent Cycle Status: " . ($cycle->status ?? 'NOT FOUND') . "\n";
exit;

// Check payment orders in DB
echo "=== PAYMENT ORDERS IN DB ===\n";
$orders = DB::table('payment_orders')->orderByDesc('created_at')->get();
foreach ($orders as $o) {
    echo "ID: {$o->id}\n";
    echo "  paymob_order_id: {$o->paymob_order_id}\n";
    echo "  status: {$o->status}\n";
    echo "  created_at: {$o->created_at}\n";
    echo "  payment_url: " . substr($o->payment_url ?? 'NULL', 0, 80) . "\n\n";
}

// Now test a fresh Paymob order creation (no cache)
Cache::forget('paymob_auth_token');
echo "=== FRESH PAYMOB TEST ===\n";
$paymob = app(App\Services\PaymobService::class);
$token = $paymob->getAuthToken();
echo "Auth token: " . ($token ? 'OK' : 'FAILED') . "\n";
if ($token) {
    $testKey = 'debug_test_' . time();
    $orderId = $paymob->createOrder($token, 5000, $testKey);
    echo "Order created in Paymob: " . ($orderId ?? 'FAILED') . "\n";
    if ($orderId) {
        $key = $paymob->createPaymentKey($token, $orderId, 5000, ['first_name'=>'Test','last_name'=>'User','email'=>'t@t.com','phone_number'=>'01000000000']);
        echo "Payment key: " . ($key ? 'OK' : 'FAILED') . "\n";
        if ($key) {
            echo "URL: " . $paymob->getPaymentUrl($key) . "\n";
        }
    }
}
exit;

// Login as Ahmad and join the target apartment
$ahmadLogin = Http::withHeaders(['Accept' => 'application/json'])
    ->post("{$base}/api/auth/login", ['login' => 'ahmad@gmail.com', 'password' => 'ahmad@gmail.com']);
$ahmadToken = $ahmadLogin->json()['data']['token'] ?? null;
echo "Ahmad login: " . $ahmadLogin->status() . "\n";
if (!$ahmadToken) { echo $ahmadLogin->body(); exit; }

$aptId = '019e65a5-e344-72cc-bb94-1897c6e85ebc';
echo "\n=== POST /api/apartments/{$aptId}/join ===\n";
$join = Http::withHeaders(['Accept' => 'application/json', 'Authorization' => "Bearer {$ahmadToken}"])
    ->post("{$base}/api/apartments/{$aptId}/join");
echo "Status: " . $join->status() . "\n";
$data = $join->json()['data'] ?? [];
echo "membership_status: " . ($data['membership']['membership_status'] ?? 'N/A') . "\n";
echo "payment_order status: " . ($data['payment_order']['status'] ?? 'N/A') . "\n";
echo "payment_url: " . ($data['payment_order']['payment_url'] ?? 'NONE') . "\n";
echo "paymob_order_id: " . ($data['payment_order']['paymob_order_id'] ?? 'NONE') . "\n";
exit;

// Login as admin
$adminLogin = Http::withHeaders(['Accept' => 'application/json'])
    ->post("{$base}/api/auth/login", ['login' => 'admin@sukoon.test', 'password' => 'password123']);
$adminToken = $adminLogin->json()['data']['token'] ?? null;
echo "Admin login: " . $adminLogin->status() . " | token: " . ($adminToken ? substr($adminToken,0,20).'...' : 'FAILED') . "\n\n";
if (!$adminToken) exit;

$aptId = '019e619a-603b-710b-8ade-cf9e93d0ad55';
$userId = '019e6595-2611-728e-b00c-164b3a6fbcd6';

echo "=== POST /api/apartments/{$aptId}/remove-member ===\n";
$r = Http::withHeaders(['Accept' => 'application/json', 'Authorization' => "Bearer {$adminToken}"])
    ->post("{$base}/api/apartments/{$aptId}/remove-member", ['user_id' => $userId]);
echo "Status: " . $r->status() . "\n";
echo $r->body() . "\n";
exit;

echo "=== ENV CHECK ===\n";
echo "BASE_URL: " . env('PAYMOB_BASE_URL', 'https://accept.paymob.com') . "\n";
echo "IFRAME_BASE_URL: " . env('PAYMOB_IFRAME_BASE_URL', 'https://accept-alpha.paymob.com') . "\n";
echo "INTEGRATION_ID: " . env('PAYMOB_INTEGRATION_ID') . "\n";
echo "IFRAME_ID: " . env('PAYMOB_IFRAME_ID') . "\n\n";

echo "=== TEST PAYMOB PAYMENT LINK ===\n";
$paymob = app(App\Services\PaymobService::class);
$token = $paymob->getAuthToken();
echo "Auth token: " . ($token ? substr($token,0,20).'...' : 'FAILED') . "\n";

if ($token) {
    $orderId = $paymob->createOrder($token, 10000, 'test_link_check_' . time());
    echo "Order ID: " . ($orderId ?? 'FAILED') . "\n";

    if ($orderId) {
        $key = $paymob->createPaymentKey($token, $orderId, 10000, [
            'first_name' => 'Test', 'last_name' => 'User',
            'email' => 'test@example.com', 'phone_number' => '01000000000'
        ]);
        echo "Payment key: " . ($key ? substr($key,0,30).'...' : 'FAILED') . "\n";

        if ($key) {
            $url = $paymob->getPaymentUrl($key);
            echo "Payment URL: {$url}\n";
        }
    }
}
exit;

// Login as Ahmad
$ahmadLogin = Http::withHeaders(['Accept' => 'application/json'])
    ->post("{$base}/api/auth/login", ['login' => 'ahmad@gmail.com', 'password' => 'ahmad@gmail.com']);
$ahmadToken = $ahmadLogin->json()['data']['token'] ?? null;
echo "Ahmad login: " . $ahmadLogin->status() . " | token: " . ($ahmadToken ? substr($ahmadToken,0,20).'...' : 'FAILED') . "\n\n";
if (!$ahmadToken) exit;

// Call join
echo "=== POST /api/apartments/{$targetApt}/join ===\n";
$join = Http::withHeaders(['Accept' => 'application/json', 'Authorization' => "Bearer {$ahmadToken}"])
    ->post("{$base}/api/apartments/{$targetApt}/join");
echo "Status: " . $join->status() . "\n";
echo $join->body() . "\n";
exit;

// ── STEP 1: Login as owner ────────────────────────────────────────────
echo "=== STEP 1: Login as owner@sukoon.test ===\n";
$login = Http::withHeaders(['Accept' => 'application/json'])
    ->post("{$base}/api/auth/login", [
        'login'    => 'owner@sukoon.test',
        'password' => 'password123',
    ]);
echo "Status: " . $login->status() . "\n";
$ownerToken = $login->json()['data']['token'] ?? $login->json()['token'] ?? null;
if (!$ownerToken) {
    echo "Login failed:\n" . $login->body() . "\n";
    exit;
}
echo "Owner token: " . substr($ownerToken, 0, 40) . "...\n\n";

// ── STEP 2: Create apartment ──────────────────────────────────────────
echo "=== STEP 2: Create apartment ===\n";
$apt = Http::withHeaders(['Accept' => 'application/json', 'Authorization' => "Bearer {$ownerToken}"])
    ->post("{$base}/api/apartments", [
        'title'           => 'Test Flow Apartment',
        'description'     => 'Created for payment flow test',
        'address'         => '123 Test St, Cairo',
        'city'            => 'Cairo',
        'price'           => 3000,
        'capacity'        => 1,
        'gender'          => 'male',
        'gender_allowed'  => 'male',
        'rent_duration'   => 1,
        'insurance'       => 500,
        'available_date'  => '2026-06-01',
        'latitude'        => 30.0444,
        'longitude'       => 31.2357,
        'rooms_count'     => 2,
        'beds_count'      => 1,
    ]);
echo "Status: " . $apt->status() . "\n";
$aptId = $apt->json()['data']['id'] ?? null;
echo "Apartment ID: {$aptId}\n";

// ── STEP 3: Login as ahmad@gmail.com ─────────────────────────────────
echo "\n=== STEP 3: Login as ahmad@gmail.com ===\n";
$ahmadLogin = Http::withHeaders(['Accept' => 'application/json'])
    ->post("{$base}/api/auth/login", [
        'login'    => 'ahmad@gmail.com',
        'password' => 'ahmad@gmail.com',
    ]);
echo "Status: " . $ahmadLogin->status() . "\n";
$ahmadToken = $ahmadLogin->json()['data']['token'] ?? $ahmadLogin->json()['token'] ?? null;
if (!$ahmadToken) {
    echo "Login failed:\n" . $ahmadLogin->body() . "\n";
    exit;
}
echo "Ahmad token: " . substr($ahmadToken, 0, 40) . "...\n\n";

// ── STEP 4: Fetch apartments list as ahmad ───────────────────────────
echo "=== STEP 4: Fetch apartments list ===\n";
$list = Http::withHeaders(['Accept' => 'application/json', 'Authorization' => "Bearer {$ahmadToken}"])
    ->get("{$base}/api/apartments");
echo "Status: " . $list->status() . "\n";
$apartments = $list->json()['data'] ?? [];
echo "Total apartments returned: " . count($apartments) . "\n\n";
foreach ($apartments as $a) {
    echo "  ID: " . ($a['id'] ?? 'N/A') . "\n";
    echo "  Title: " . ($a['title'] ?? $a['name'] ?? 'N/A') . "\n";
    echo "  Status: " . ($a['status'] ?? 'N/A') . " | Verification: " . ($a['verification_status'] ?? 'N/A') . "\n";
    echo "  Capacity: " . ($a['capacity'] ?? 'N/A') . " | Male: " . ($a['male_count'] ?? 0) . " | Female: " . ($a['female_count'] ?? 0) . "\n";
    echo "  Price: " . ($a['price'] ?? 'N/A') . " EGP | Insurance: " . ($a['insurance'] ?? 'N/A') . " EGP\n";
    echo "  ---\n";
}
