# Complete Paymob Payment Integration Guide

This guide details the exact implementation of the multi-tenant Paymob payment gateway integration within the Sukoon application. It outlines the schema, low-level APIs, logic controllers, background job loops, and the webhook handling processes. Use this brief to replicate or modify the payment system in the `sukoon_dev` project.

---

## 1. System Topology & Flow

The payment architecture consists of 5 states:
1. **Contract Approval**: Triggered by admin.
2. **Cycle Initiation**: Creates a `rent_cycle` and computes pricing shares for each tenant.
3. **Paymob Invoice Generation**: Calls Paymob API to get checkout URLs.
4. **Webhook Receipt**: Processes asynchronous Paymob success callbacks, updates billing/user/membership statuses, and pays out the owner.
5. **Scheduler Checks**: Checks deadlines; if any tenant fails to pay within 24 hours, the cycle is cancelled, paid tenants are refunded, and the apartment is reopened.

```
                  [ Admin approves contracts ]
                                │
                                ▼
                   [ RentCycleService: initiate ]
                                │
                                ├── Split rent & platform fees among tenants
                                └── Call Paymob API to generate payment URLs
                                │
              ┌─────────────────┴─────────────────┐
              ▼                                   ▼
      [ Tenant pays online ]             [ 24-Hour Timer Expires ]
              │                                   │
              ▼                                   ▼
     [ Webhook Callback ]                [ Scheduler Job runs ]
              │                                   │
      ┌───────┴───────┐                           ▼
      ▼               ▼                  [ Expire pending bills ]
 [Not all paid]  [All paid]                       │
      │               │                  [ Refund paid tenants ]
      ▼               ▼                           │
  [Nothing]      [Activate cycle]                 ▼
                 [Activate members]       [Reset apartment status]
                 [Notify tenants]                 │
                 [Disburse owner]        [Notify all & remove members]
```

---

## 2. Configuration & Env Variables

Ensure the following parameters are added to your `.env` and mapped inside `config/payment.php`:

### `.env` Setup:
```env
PAYMOB_API_KEY=zx_abc123...
PAYMOB_INTEGRATION_ID=123456
PAYMOB_IFRAME_ID=789012
PAYMOB_HMAC_SECRET=hmac_secret_key...
PAYMOB_PLATFORM_SUB_ACCOUNT=platform_sub_id
PLATFORM_WALLET_NUMBER=01000000000
```

### `config/payment.php` Setup:
```php
<?php
return [
    'paymob_api_key'        => env('PAYMOB_API_KEY', ''),
    'paymob_integration_id' => env('PAYMOB_INTEGRATION_ID', ''),
    'paymob_iframe_id'      => env('PAYMOB_IFRAME_ID', ''),
    'paymob_hmac_secret'    => env('PAYMOB_HMAC_SECRET', ''),
    'platform_sub_account'  => env('PAYMOB_PLATFORM_SUB_ACCOUNT', ''),
    'platform_wallet'       => env('PLATFORM_WALLET_NUMBER', ''),
];
```

---

## 3. Database Schema

The database utilizes 5 tables to maintain full ledger transparency and track cycle lifecycles.

### Migration Schema Blueprint:
```php
Schema::create('rent_cycles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('apartment_id')->constrained('apartments')->cascadeOnDelete();
    $table->unsignedSmallInteger('cycle_number')->default(1);
    $table->dateTime('starts_at');
    $table->dateTime('ends_at');
    $table->enum('status', ['pending_payment', 'active', 'completed', 'cancelled'])->default('pending_payment');
    $table->timestamps();

    $table->unique(['apartment_id', 'cycle_number']);
});

Schema::create('payment_orders', function (Blueprint $table) {
    $table->id();
    $table->string('idempotency_key')->unique(); // e.g. "cycle_{cycle_id}_user_{user_id}"
    $table->foreignId('rent_cycle_id')->constrained('rent_cycles')->cascadeOnDelete();
    $table->foreignId('apartment_id')->constrained('apartments')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->unsignedInteger('amount_cents'); // Total to pay in cents
    $table->json('breakdown'); // Breakdown details e.g. {"rent_cents": X, "insurance_cents": Y, "platform_fee_cents": Z}
    $table->string('paymob_order_id')->nullable()->unique();
    $table->text('paymob_payment_key')->nullable();
    $table->text('payment_url')->nullable();
    $table->enum('status', ['pending', 'paid', 'refunded', 'expired', 'failed'])->default('pending');
    $table->dateTime('paid_at')->nullable();
    $table->dateTime('expires_at');
    $table->timestamps();

    $table->index(['rent_cycle_id', 'user_id']);
    $table->index(['apartment_id', 'status']);
});

Schema::create('insurance_payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('apartment_id')->constrained('apartments')->cascadeOnDelete();
    $table->foreignId('payment_order_id')->constrained('payment_orders')->cascadeOnDelete();
    $table->unsignedInteger('amount_cents');
    $table->dateTime('paid_at');
    $table->timestamps();

    $table->unique(['user_id', 'apartment_id']); // Unique per tenant-apartment pairing
});

Schema::create('transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('payment_order_id')->nullable()->constrained('payment_orders')->nullOnDelete();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('apartment_id')->constrained('apartments')->cascadeOnDelete();
    $table->enum('type', ['charge', 'refund', 'payout_owner', 'payout_platform']);
    $table->enum('direction', ['in', 'out']);
    $table->unsignedInteger('amount_cents');
    $table->string('currency', 3)->default('EGP');
    $table->string('paymob_transaction_id')->nullable()->unique();
    $table->enum('status', ['success', 'failed', 'pending'])->default('pending');
    $table->json('metadata')->nullable();
    $table->timestamps();

    $table->index(['apartment_id', 'type']);
    $table->index(['user_id', 'type']);
});

Schema::create('refund_requests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('payment_order_id')->constrained('payment_orders')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->text('reason')->nullable();
    $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
    $table->dateTime('processed_at')->nullable();
    $table->timestamps();
});
```

---

## 4. API Service Wrappers (`PaymobService`)

The low-level `PaymobService.php` acts as the interface to the Paymob Acceptance API. It uses Laravel's `Http` facade.

### A. Auth Token Retrieval (Cached)
Retrieves a short-lived token valid for 60 minutes. We cache it for 45 minutes (2700s).
```php
public function getAuthToken(): string
{
    return Cache::remember('paymob_auth_token', 2700, function () {
        $response = Http::post("https://accept.paymob.com/api/auth/tokens", [
            'api_key' => $this->apiKey,
        ])->throw()->json();

        return $response['token'];
    });
}
```

### B. Create Paymob Order
Registers the transaction order in Paymob's database.
```php
public function createOrder(string $token, string $merchantOrderId, int $amountCents): int
{
    $response = Http::withToken($token)
        ->post("https://accept.paymob.com/api/ecommerce/orders", [
            'amount_cents'       => $amountCents,
            'currency'           => 'EGP',
            'merchant_order_id'  => $merchantOrderId,
            'items'              => [],
        ])->throw()->json();

    return $response['id'];
}
```

### C. Get Payment Key
Requests a security payment token for the specific integration (card or wallet).
```php
public function getPaymentKey(string $token, int $paymobOrderId, int $amountCents, array $billingData): string
{
    $response = Http::withToken($token)
        ->post("https://accept.paymob.com/api/acceptance/payment_keys", [
            'amount_cents'          => $amountCents,
            'currency'              => 'EGP',
            'expiration'            => 86400, // 24 Hours
            'integration_id'        => (int) $this->integrationId,
            'lock_order_when_paid'  => true,
            'order_id'              => $paymobOrderId,
            'billing_data'          => $billingData,
        ])->throw()->json();

    return $response['token'];
}
```

### D. Build Checkout Link
Returns the target Paymob checkout iframe URL with the generated token:
```php
public function buildPaymentUrl(string $paymentKey): string
{
    return "https://accept.paymob.com/api/acceptance/iframes/{$this->iframeId}?payment_token={$paymentKey}";
}
```

### E. Refund a Captured Transaction
```php
public function refundTransaction(string $paymobTransactionId, int $amountCents): array
{
    $token = $this->getAuthToken();

    return Http::withToken($token)
        ->post("https://accept.paymob.com/api/acceptance/void_refund/refund", [
            'transaction_id' => $paymobTransactionId,
            'amount_cents'   => $amountCents,
        ])->throw()->json();
}
```

---

## 5. High-Level Orchestrator (`RentCycleService`)

The high-level logic splits expenses and provisions payment links.

### `initiatePaymentCycle(int $apartmentId)`:
1. Determines next `cycle_number` for the apartment.
2. Creates the `rent_cycles` entry in state `pending_payment`.
3. Selects all apartment members with status `pending` or `active`.
4. Calculates per-tenant amounts (ceiled to closest cent):
   - `rent = (apartment.price * 100) / capacity`
   - `insurance = (apartment.insurance * 100) / capacity` (Only charged if tenant has no historical `insurance_payments` row).
   - `platform_fee = (apartment.price * 100 / 2) / capacity` (Only charged if `user.has_paid_platform_fee` is `false`).
5. Generates an `idempotency_key` string: `"cycle_{cycle_id}_user_{user_id}"`.
6. Checks if a `payment_orders` with the key already exists to prevent duplicate calls.
7. Calls `PaymobService::createPaymentLink()` inside a `try/catch`.
8. Saves the resulting `payment_url`, `paymob_order_id`, and parameters into `payment_orders` with status `pending`.
9. Sets the user's member deadline `payment_deadline` to `now() + 24 hours`.
10. Dispatches FCM notification to the tenant containing the `payment_url`.

---

## 6. Webhook Callback & HMAC Verification

### Webhook Controller (`PaymobWebhookController.php`)
- Route: `/api/paymob/webhook` (Exclude from CSRF, public POST route). Since it's registered in the API route file in Laravel, it skips CSRF automatically.
- Processes both successful charge and refund webhook signals.

### HMAC Signature Check:
To prevent request spoofing, the received signature `hmac` query parameter must match the computed signature:
```php
public function verifyHmac(array $obj, string $receivedHmac): bool
{
    $fields = [
        'amount_cents'             => $obj['amount_cents'] ?? '',
        'created_at'               => $obj['created_at'] ?? '',
        'currency'                 => $obj['currency'] ?? '',
        'error_occured'            => $this->boolStr($obj['error_occured'] ?? false),
        'has_parent_transaction'   => $this->boolStr($obj['has_parent_transaction'] ?? false),
        'id'                       => $obj['id'] ?? '',
        'integration_id'           => $obj['integration_id'] ?? '',
        'is_3d_secure'             => $this->boolStr($obj['is_3d_secure'] ?? false),
        'is_auth'                  => $this->boolStr($obj['is_auth'] ?? false),
        'is_capture'               => $this->boolStr($obj['is_capture'] ?? false),
        'is_refunded'              => $this->boolStr($obj['is_refunded'] ?? false),
        'is_standalone_payment'    => $this->boolStr($obj['is_standalone_payment'] ?? false),
        'is_voided'                => $this->boolStr($obj['is_voided'] ?? false),
        'order'                    => $obj['order']['id'] ?? '',
        'owner'                    => $obj['owner'] ?? '',
        'pending'                  => $this->boolStr($obj['pending'] ?? false),
        'source_data.pan'          => $obj['source_data']['pan'] ?? '',
        'source_data.sub_type'     => $obj['source_data']['sub_type'] ?? '',
        'source_data.type'         => $obj['source_data']['type'] ?? '',
        'success'                  => $this->boolStr($obj['success'] ?? false),
    ];

    ksort($fields);
    $concatenated = implode('', $fields);
    $computed     = hash_hmac('sha512', $concatenated, $this->hmacSecret);

    return hash_equals($computed, $receivedHmac);
}

private function boolStr(mixed $val): string
{
    return filter_var($val, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
}
```

---

## 7. Background Jobs Lifecycle

### A. `HandleSuccessfulPaymentJob`
Triggered upon a validated successful payment webhook.
- Checks if the order is already marked `paid` (Idempotency check).
- Updates the payment order status to `paid`.
- Logs the credit entry in `transactions` with type `'charge'`.
- Provisions `insurance_payments` and updates `user.has_paid_platform_fee = true` if applicable.
- Verifies if all other tenant orders under this `rent_cycle` are `paid`.
- **If all tenants paid**:
  - Activates the cycle: `cycle.status = 'active'`.
  - Marks the apartment as rented: `apartment.status = 'rented'`.
  - Activates members: `membership_status = 'active'`.
  - Dispatches FCM notification revealing the landlord's contact information and the apartment's exact coordinates.
  - Sums up the rent portion and dispatches `PayoutToOwnerJob`.

### B. `PayoutToOwnerJob`
Handles wallet disbursement for the owner's share.
- Queries `owner.payout_number`.
- Dispatches a Paymob wallet transfer API request:
  - `POST https://accept.paymob.com/api/acceptance/pay_with_wallet`
  - Body: `{"source": {"identifier": owner->payout_number, "subtype": "WALLET"}, "amount_cents": amount}`
- Logs `'payout_owner'` `'out'` record in transactions ledger.

### C. `CheckPaymentDeadlinesJob` (Runs every 15 mins)
Finds active cycles in `pending_payment` where at least one tenant missed their payment deadline.
- Marks all unpaid orders as `expired`.
- Iterates over paid orders, reads their `paymob_transaction_id` and executes a refund through Paymob API. Sets order status to `refunded`.
- Sets `rent_cycle.status = 'cancelled'`.
- Resets `apartment.status = 'open'`.
- Removes all tenants from the apartment members table.
- Notifies everyone via FCM.

---

## 8. Scheduled Task Registrations
Ensure these scheduler configs are placed inside `routes/console.php`:

```php
use App\Jobs\CheckPaymentDeadlinesJob;
use App\Jobs\StartNextRentCycleJob;
use Illuminate\Support\Facades\Schedule;

// Expiration Check (Every 15 minutes)
Schedule::job(new CheckPaymentDeadlinesJob(
    app(\App\Modules\Payment\Services\PaymobService::class)
))->everyFifteenMinutes()->name('check-payment-deadlines')->withoutOverlapping();

// Rent Cycle Rollover (Daily)
Schedule::job(new StartNextRentCycleJob)->daily()->name('start-next-rent-cycle')->withoutOverlapping();
```
