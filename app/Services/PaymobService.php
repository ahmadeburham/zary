<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class PaymobService
{
    protected ?string $apiKey;
    protected ?string $integrationId;
    protected ?string $iframeId;
    protected ?string $hmacSecret;
    protected string $baseUrl;
    protected string $iframeBaseUrl;

    public function __construct()
    {
        $this->apiKey        = env('PAYMOB_API_KEY');
        $this->integrationId = env('PAYMOB_INTEGRATION_ID');
        $this->iframeId      = env('PAYMOB_IFRAME_ID');
        $this->hmacSecret    = env('PAYMOB_HMAC_SECRET');
        $this->baseUrl       = env('PAYMOB_BASE_URL', 'https://accept.paymob.com');
        $this->iframeBaseUrl = env('PAYMOB_IFRAME_BASE_URL', 'https://accept.paymob.com');
    }

    /**
     * Get Authentication Token (Cached for 50 minutes)
     */
    public function getAuthToken(): ?string
    {
        Log::debug("Paymob: Getting auth token...");
        Log::debug("Paymob: Base URL = {$this->baseUrl}");
        Log::debug("Paymob: API Key present = " . ($this->apiKey ? 'YES' : 'NO'));

        return Cache::remember('paymob_auth_token', 3000, function () {
            try {
                Log::debug("Paymob: Requesting auth token from {$this->baseUrl}/api/auth/tokens");

                $response = Http::post("{$this->baseUrl}/api/auth/tokens", [
                    'api_key' => $this->apiKey
                ]);

                Log::debug("Paymob: Auth response status = " . $response->status());
                Log::debug("Paymob: Auth response body = " . $response->body());

                if ($response->failed()) {
                    Log::error("Paymob Auth Failed: " . $response->body());
                    return null;
                }

                $token = $response->json()['token'] ?? null;
                Log::debug("Paymob: Auth token obtained = " . ($token ? 'YES (length: ' . strlen($token) . ')' : 'NO'));
                return $token;
            } catch (Exception $e) {
                Log::error("Paymob Auth Exception: " . $e->getMessage());
                Log::error("Paymob Auth Exception Trace: " . $e->getTraceAsString());
                return null;
            }
        });
    }

    /**
     * Register Order on Paymob
     */
    public function createOrder(string $token, int $amountCents, string $merchantOrderId): ?int
    {
        Log::debug("Paymob: Creating order with amount={$amountCents}, merchantOrderId={$merchantOrderId}");
        Log::debug("Paymob: Integration ID = {$this->integrationId}");

        try {
            $response = Http::post("{$this->baseUrl}/api/ecommerce/orders", [
                'auth_token' => $token,
                'delivery_needed' => 'false',
                'amount_cents' => (string) $amountCents,
                'currency' => 'EGP',
                'merchant_order_id' => $merchantOrderId,
                'items' => []
            ]);

            Log::debug("Paymob: Order creation response status = " . $response->status());
            Log::debug("Paymob: Order creation response body = " . $response->body());

            if ($response->failed()) {
                Log::error("Paymob Order Creation Failed: " . $response->body());
                return null;
            }

            $orderId = $response->json()['id'] ?? null;
            Log::debug("Paymob: Order created with ID = " . ($orderId ?: 'NULL'));
            return $orderId !== null ? (int) $orderId : null;
        } catch (Exception $e) {
            Log::error("Paymob Order Creation Exception: " . $e->getMessage());
            Log::error("Paymob Order Creation Exception Trace: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Generate Payment Key
     */
    public function createPaymentKey(string $token, int $paymobOrderId, int $amountCents, array $billingData): ?string
    {
        Log::debug("Paymob: Creating payment key for orderId={$paymobOrderId}, amount={$amountCents}");
        Log::debug("Paymob: Billing data = " . json_encode($billingData));

        try {
            $payload = [
                'auth_token' => $token,
                'amount_cents' => (string) $amountCents,
                'expiration' => 3600 * 24, // 24 hours
                'order_id' => (string) $paymobOrderId,
                'billing_data' => [
                    'apartment' => $billingData['apartment'] ?? 'NA',
                    'floor' => $billingData['floor'] ?? 'NA',
                    'street' => $billingData['street'] ?? 'NA',
                    'building' => $billingData['building'] ?? 'NA',
                    'shipping_method' => 'NA',
                    'postal_code' => 'NA',
                    'city' => $billingData['city'] ?? 'Cairo',
                    'country' => 'Egypt',
                    'last_name' => $billingData['last_name'] ?? 'Tenant',
                    'state' => $billingData['state'] ?? 'NA',
                    'first_name' => $billingData['first_name'] ?? 'User',
                    'email' => $billingData['email'] ?? 'test@example.com',
                    'phone_number' => $billingData['phone_number'] ?? '01000000000',
                ],
                'currency' => 'EGP',
                'integration_id' => (int) $this->integrationId,
                'lock_order_to_token' => true
            ];
            Log::debug("Paymob: Payment key request payload = " . json_encode($payload));

            $response = Http::post("{$this->baseUrl}/api/acceptance/payment_keys", $payload);

            Log::debug("Paymob: Payment key response status = " . $response->status());
            Log::debug("Paymob: Payment key response body = " . $response->body());

            if ($response->failed()) {
                Log::error("Paymob Payment Key Failed: " . $response->body());
                return null;
            }

            $paymentKey = $response->json()['token'] ?? null;
            Log::debug("Paymob: Payment key obtained = " . ($paymentKey ? 'YES (length: ' . strlen($paymentKey) . ')' : 'NO'));
            return $paymentKey;
        } catch (Exception $e) {
            Log::error("Paymob Payment Key Exception: " . $e->getMessage());
            Log::error("Paymob Payment Key Exception Trace: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Generate Webhook/Iframe URL
     */
    public function getPaymentUrl(string $paymentKey): string
    {
        $url = "{$this->iframeBaseUrl}/api/acceptance/iframes/{$this->iframeId}?payment_token={$paymentKey}";
        Log::debug("Paymob: Generated payment URL = {$url}");
        return $url;
    }

    /**
     * Process Refund via Paymob
     */
    public function refund(string $transactionId, int $amountCents): ?array
    {
        try {
            $token = $this->getAuthToken();
            if (!$token) {
                return null;
            }

            $response = Http::post("{$this->baseUrl}/api/acceptance/void_refund/refund", [
                'auth_token' => $token,
                'transaction_id' => $transactionId,
                'amount_cents' => $amountCents
            ]);

            if ($response->failed()) {
                Log::error("Paymob Refund Failed: " . $response->body());
                return null;
            }

            return $response->json();
        } catch (Exception $e) {
            Log::error("Paymob Refund Exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Disburse payout to owner's wallet via Paymob Disbursement API.
     * In sandbox mode, this simulates a successful disbursement.
     */
    public function payoutToWallet(string $walletNumber, int $amountCents): ?array
    {
        try {
            $token = $this->getAuthToken();
            if (!$token) {
                return null;
            }

            // Paymob Disbursement API (Transfer endpoint)
            // In sandbox, this may not be available — simulate success
            $isSandbox = str_contains(config('payment.paymob_api_key', ''), 'sandbox')
                || app()->environment('local', 'testing');

            if ($isSandbox) {
                Log::info("Paymob Disbursement (SANDBOX): Simulating payout of {$amountCents} cents to wallet {$walletNumber}");
                return [
                    'success' => true,
                    'simulated' => true,
                    'wallet_number' => $walletNumber,
                    'amount_cents' => $amountCents,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            // Production: Use Paymob's Transfer/Disbursement API
            $response = Http::post('https://accept.paymob.com/api/acceptance/disburse', [
                'auth_token' => $token,
                'amount_cents' => $amountCents,
                'msisdn' => $walletNumber,
                'currency' => 'EGP',
            ]);

            if ($response->failed()) {
                Log::error("Paymob Wallet Payout Failed: " . $response->body());
                return null;
            }

            return $response->json();
        } catch (Exception $e) {
            Log::error("Paymob Wallet Payout Exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch transactions for a Paymob ecommerce order (transaction inquiry).
     */
    public function inquireOrderTransactions(int $paymobOrderId): ?array
    {
        $token = $this->getAuthToken();
        if (!$token) {
            return null;
        }

        try {
            $response = Http::post("{$this->baseUrl}/api/ecommerce/orders/transaction_inquiry", [
                'auth_token' => $token,
                'order_id'   => (string) $paymobOrderId,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Paymob transaction_inquiry failed', [
                'order_id' => $paymobOrderId,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
        } catch (Exception $e) {
            Log::error('Paymob transaction_inquiry exception: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Pick the first successful, non-pending transaction from an inquiry response.
     */
    public function pickSuccessfulTransaction(?array $inquiry, int $expectedAmountCents = 0): ?array
    {
        if ($inquiry === null) {
            return null;
        }

        $list = [];
        if (isset($inquiry['transactions']) && is_array($inquiry['transactions'])) {
            $list = $inquiry['transactions'];
        } elseif (array_is_list($inquiry)) {
            $list = $inquiry;
        } elseif (isset($inquiry['id'])) {
            $list = [$inquiry];
        }

        $matchesAmount = function (array $txn) use ($expectedAmountCents): bool {
            if ($expectedAmountCents <= 0) {
                return true;
            }
            return (int) ($txn['amount_cents'] ?? 0) === $expectedAmountCents;
        };

        foreach ($list as $txn) {
            if (!is_array($txn)) {
                continue;
            }
            $success = filter_var($txn['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $pending = filter_var($txn['pending'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($success && !$pending && $matchesAmount($txn)) {
                return $txn;
            }
        }

        foreach ($list as $txn) {
            if (!is_array($txn)) {
                continue;
            }
            $success = filter_var($txn['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $pending = filter_var($txn['pending'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($success && !$pending) {
                return $txn;
            }
        }

        return null;
    }

    /**
     * Verify callback HMAC
     */
    public function verifyHmac(array $data, string $receivedHmac): bool
    {
        try {
            // Concatenate specific fields from the payload in standard Paymob order
            $concatString = "";
            $concatString .= $data['amount_cents'] ?? '';
            $concatString .= $data['created_at'] ?? '';
            $concatString .= $data['currency'] ?? '';
            $concatString .= isset($data['error_occured']) ? ($data['error_occured'] ? 'true' : 'false') : '';
            $concatString .= isset($data['has_parent_transaction']) ? ($data['has_parent_transaction'] ? 'true' : 'false') : '';
            $concatString .= $data['id'] ?? '';
            $concatString .= $data['integration_id'] ?? '';
            $concatString .= isset($data['is_3d_secure']) ? ($data['is_3d_secure'] ? 'true' : 'false') : '';
            $concatString .= isset($data['is_auth']) ? ($data['is_auth'] ? 'true' : 'false') : '';
            $concatString .= isset($data['is_capture']) ? ($data['is_capture'] ? 'true' : 'false') : '';
            $concatString .= isset($data['is_refunded']) ? ($data['is_refunded'] ? 'true' : 'false') : '';
            $concatString .= isset($data['is_standalone_payment']) ? ($data['is_standalone_payment'] ? 'true' : 'false') : '';
            $concatString .= isset($data['is_voided']) ? ($data['is_voided'] ? 'true' : 'false') : '';
            $concatString .= isset($data['order']['id']) ? $data['order']['id'] : ($data['order'] ?? '');
            $concatString .= $data['owner'] ?? '';
            $concatString .= isset($data['pending']) ? ($data['pending'] ? 'true' : 'false') : '';
            $concatString .= $data['source_data']['pan'] ?? '';
            $concatString .= $data['source_data']['sub_type'] ?? '';
            $concatString .= $data['source_data']['type'] ?? '';
            $concatString .= isset($data['success']) ? ($data['success'] ? 'true' : 'false') : '';

            $calculatedHmac = hash_hmac('sha512', $concatString, $this->hmacSecret);
            return hash_equals($calculatedHmac, $receivedHmac);
        } catch (Exception $e) {
            Log::error("Paymob HMAC Verification Exception: " . $e->getMessage());
            return false;
        }
    }
}
