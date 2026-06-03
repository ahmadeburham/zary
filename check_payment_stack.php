<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== CONFIG ===\n";
echo 'APP_URL=' . env('APP_URL') . "\n";
echo 'PAYMOB_INTEGRATION_ID=' . env('PAYMOB_INTEGRATION_ID') . "\n";
echo 'PAYMOB_IFRAME_ID=' . env('PAYMOB_IFRAME_ID') . "\n";
echo 'PAYMOB_BASE_URL=' . env('PAYMOB_BASE_URL') . "\n";
echo 'PAYMOB_HMAC_SET=' . (env('PAYMOB_HMAC_SECRET') ? 'yes (' . strlen(env('PAYMOB_HMAC_SECRET')) . ' chars)' : 'no') . "\n";
echo 'Expected webhook=' . rtrim(env('APP_URL'), '/') . "/api/payments/webhook/paymob\n";
echo 'Expected redirect=' . rtrim(env('APP_URL'), '/') . "/api/acceptance/post_pay\n\n";

echo "=== PAYMOB AUTH ===\n";
$pm = app(App\Services\PaymobService::class);
$token = $pm->getAuthToken();
echo ($token ? "Auth OK (len " . strlen($token) . ")\n" : "Auth FAILED\n");

echo "\n=== LAST PAID ORDER (paymob 537701926) ===\n";
$o = App\Models\PaymentOrder::where('paymob_order_id', '537701926')->first();
if ($o) {
    echo "id={$o->id} status={$o->status} paid_at=" . ($o->paid_at ?? 'null') . "\n";
} else {
    echo "not found\n";
}

echo "\n=== PAYMOB INQUIRY (537701926) ===\n";
$inq = $pm->inquireOrderTransactions(537701926);
$txn = $pm->pickSuccessfulTransaction($inq, 931800);
echo 'inquiry_type=' . gettype($inq) . "\n";
if (is_array($inq)) {
    echo 'inquiry_keys=' . implode(',', array_keys($inq)) . "\n";
}
echo 'successful_txn_id=' . ($txn['id'] ?? 'none') . "\n";

echo "\n=== HMAC ALGORITHM ROUNDTRIP ===\n";
$obj = [
    'id' => 471791398,
    'amount_cents' => 931800,
    'created_at' => '2026-06-02T05:28:00.000000',
    'currency' => 'EGP',
    'error_occured' => false,
    'has_parent_transaction' => false,
    'integration_id' => (int) env('PAYMOB_INTEGRATION_ID'),
    'is_3d_secure' => false,
    'is_auth' => false,
    'is_capture' => false,
    'is_refunded' => false,
    'is_standalone_payment' => true,
    'is_voided' => false,
    'order' => ['id' => 537701926],
    'owner' => 1,
    'pending' => false,
    'source_data' => ['pan' => '1111', 'sub_type' => 'Visa', 'type' => 'card'],
    'success' => true,
];
$concatString = '';
$concatString .= $obj['amount_cents'] ?? '';
$concatString .= $obj['created_at'] ?? '';
$concatString .= $obj['currency'] ?? '';
$concatString .= isset($obj['error_occured']) ? ($obj['error_occured'] ? 'true' : 'false') : '';
$concatString .= isset($obj['has_parent_transaction']) ? ($obj['has_parent_transaction'] ? 'true' : 'false') : '';
$concatString .= $obj['id'] ?? '';
$concatString .= $obj['integration_id'] ?? '';
$concatString .= isset($obj['is_3d_secure']) ? ($obj['is_3d_secure'] ? 'true' : 'false') : '';
$concatString .= isset($obj['is_auth']) ? ($obj['is_auth'] ? 'true' : 'false') : '';
$concatString .= isset($obj['is_capture']) ? ($obj['is_capture'] ? 'true' : 'false') : '';
$concatString .= isset($obj['is_refunded']) ? ($obj['is_refunded'] ? 'true' : 'false') : '';
$concatString .= isset($obj['is_standalone_payment']) ? ($obj['is_standalone_payment'] ? 'true' : 'false') : '';
$concatString .= isset($obj['is_voided']) ? ($obj['is_voided'] ? 'true' : 'false') : '';
$concatString .= $obj['order']['id'] ?? '';
$concatString .= $obj['owner'] ?? '';
$concatString .= isset($obj['pending']) ? ($obj['pending'] ? 'true' : 'false') : '';
$concatString .= $obj['source_data']['pan'] ?? '';
$concatString .= $obj['source_data']['sub_type'] ?? '';
$concatString .= $obj['source_data']['type'] ?? '';
$concatString .= isset($obj['success']) ? ($obj['success'] ? 'true' : 'false') : '';
$hmac = hash_hmac('sha512', $concatString, env('PAYMOB_HMAC_SECRET'));
$ok = $pm->verifyHmac($obj, $hmac);
echo 'hmac_algorithm_roundtrip=' . ($ok ? 'PASS' : 'FAIL') . "\n";
echo "(If PASS, HMAC secret format is OK; webhooks fail only when Paymob sends a different HMAC than we compute)\n";
