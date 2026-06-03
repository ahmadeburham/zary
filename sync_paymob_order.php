<?php
/**
 * One-off: sync a pending PaymentOrder with Paymob by paymob_order_id.
 * Usage: php sync_paymob_order.php 537701926
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PaymentOrder;
use App\Services\PaymobService;
use Illuminate\Support\Facades\DB;

$paymobOrderId = $argv[1] ?? null;
if (!$paymobOrderId) {
    fwrite(STDERR, "Usage: php sync_paymob_order.php <paymob_order_id>\n");
    exit(1);
}

$order = PaymentOrder::findByPaymobOrderId($paymobOrderId);
if (!$order) {
    fwrite(STDERR, "No local PaymentOrder for paymob_order_id={$paymobOrderId}\n");
    exit(1);
}

echo "Local order: {$order->id} status={$order->status}\n";

if ($order->status === 'paid') {
    echo "Already paid.\n";
    exit(0);
}

$paymob = app(PaymobService::class);
$inquiry = $paymob->inquireOrderTransactions((int) $paymobOrderId);
$txn = $paymob->pickSuccessfulTransaction($inquiry, (int) $order->amount_cents);

if (!$txn) {
    echo "No successful transaction on Paymob.\n";
    echo "Inquiry response: " . json_encode($inquiry) . "\n";
    exit(2);
}

echo "Found Paymob txn id=" . ($txn['id'] ?? '?') . "\n";

$controller = app(App\Http\Controllers\PaymentController::class);
$ref = new ReflectionClass($controller);
$method = $ref->getMethod('recordSuccessfulPayment');
$method->setAccessible(true);

DB::transaction(function () use ($method, $controller, $order, $txn) {
    $method->invoke(
        $controller,
        $order,
        isset($txn['id']) ? (string) $txn['id'] : null,
        (int) ($txn['amount_cents'] ?? $order->amount_cents),
        $txn
    );
});

$order->refresh();
echo "Updated status={$order->status} paid_at={$order->paid_at}\n";
