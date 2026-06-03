<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\ApartmentMember;
use App\Models\InsurancePayment;
use App\Models\PaymentOrder;
use App\Models\RefundRequest;
use App\Models\RentCycle;
use App\Models\Transaction;
use App\Services\PaymobService;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    protected PaymobService $paymobService;

    public function __construct(PaymobService $paymobService)
    {
        $this->paymobService = $paymobService;
    }

    /** Mark apartment occupied after all cycle payments (SQLite schema lacks `rented`). */
    protected function markApartmentRented(Apartment $apartment): void
    {
        $status = DB::connection()->getDriverName() === 'sqlite' ? 'closed' : 'rented';
        $apartment->update([
            'status'    => $status,
            'rented_at' => now(),
        ]);
    }

    /**
     * Helper to dispatch FCM notification and save to database.
     */
    protected function notifyUser($userId, string $title, string $body, array $data = [])
    {
        Notification::create([
            'user_id' => $userId,
            'type' => $data['type'] ?? 'payment_update',
            'dedupe_key' => 'payment_' . uniqid(),
            'data' => array_merge(['title' => $title, 'body' => $body], $data),
            'status' => 'pending',
        ]);

        SendNotification::dispatch($userId, $title, $body, $data, ['fcm']);
    }

    /**
     * Paymob Webhook (POST callback)
     */
    public function handleWebhook(Request $request)
    {
        $hmac = $request->query('hmac') ?? $request->input('hmac');
        if (!$hmac) {
            Log::warning("Paymob Webhook: Missing HMAC signature.");
            return response()->json(['message' => 'Missing signature'], Response::HTTP_BAD_REQUEST);
        }

        $payload = $request->all();
        $obj = $payload['obj'] ?? null;
        if (!$obj) {
            Log::warning("Paymob Webhook: Missing transaction object (obj).");
            return response()->json(['message' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        // Verify HMAC authenticity
        if (!$this->paymobService->verifyHmac($obj, $hmac)) {
            Log::warning("Paymob Webhook: HMAC signature mismatch.");
            return response()->json(['message' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        }

        $success = filter_var($obj['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $paymobOrderId = is_array($obj['order'] ?? null)
            ? ($obj['order']['id'] ?? null)
            : ($obj['order'] ?? null);
        $paymobTransactionId = $obj['id'] ?? null;
        $amountCents = $obj['amount_cents'] ?? 0;

        if (!$paymobOrderId) {
            Log::warning("Paymob Webhook: Missing Paymob Order ID.");
            return response()->json(['message' => 'Missing Order ID'], Response::HTTP_BAD_REQUEST);
        }

        // Find the corresponding PaymentOrder
        $paymentOrder = PaymentOrder::findByPaymobOrderId($paymobOrderId);
        if (!$paymentOrder) {
            Log::warning("Paymob Webhook: PaymentOrder not found for Paymob Order ID: {$paymobOrderId}");
            return response()->json(['message' => 'PaymentOrder not found'], Response::HTTP_NOT_FOUND);
        }

        // If payment failed, record transaction failure and return
        if (!$success) {
            Log::info("Paymob Webhook: Transaction failed for PaymentOrder ID: {$paymentOrder->id}");
            DB::transaction(function () use ($paymentOrder, $paymobTransactionId, $amountCents, $obj) {
                Transaction::firstOrCreate(
                    ['paymob_transaction_id' => $paymobTransactionId],
                    [
                        'payment_order_id' => $paymentOrder->id,
                        'user_id' => $paymentOrder->user_id,
                        'apartment_id' => $paymentOrder->apartment_id,
                        'type' => 'charge',
                        'direction' => 'in',
                        'amount_cents' => $amountCents,
                        'currency' => 'EGP',
                        'status' => 'failed',
                        'metadata' => $obj,
                    ]
                );

                if ($paymentOrder->status === 'pending') {
                    $paymentOrder->update(['status' => 'failed']);
                }
            });

            return response()->json(['message' => 'Transaction failure recorded']);
        }

        // Handle successful payment
        if ($paymentOrder->status === 'paid') {
            return response()->json(['message' => 'Payment already processed (idempotent)']);
        }

        DB::transaction(function () use ($paymentOrder, $paymobTransactionId, $amountCents, $obj) {
            $this->recordSuccessfulPayment($paymentOrder, $paymobTransactionId, $amountCents, $obj);
        });

        return response()->json(['message' => 'Payment processed successfully']);
    }

    /**
     * Tenant requests a refund (POST /api/tenant/refund)
     */
    public function requestRefund(Request $request)
    {
        $user = $request->user();

        // Find the user's latest paid PaymentOrder belonging to a cancelled rent cycle
        $paymentOrder = PaymentOrder::where('user_id', $user->id)
            ->where('status', 'paid')
            ->whereHas('rentCycle', function ($q) {
                $q->where('status', 'cancelled');
            })
            ->latest()
            ->first();

        if (!$paymentOrder) {
            return response()->json([
                'message' => 'No eligible paid orders found for refund on a cancelled rent cycle.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Check if a refund request already exists
        $existing = RefundRequest::where('payment_order_id', $paymentOrder->id)->first();
        if ($existing) {
            return response()->json([
                'message' => 'Refund request already submitted.',
                'data' => $existing
            ]);
        }

        $refundRequest = RefundRequest::create([
            'payment_order_id' => $paymentOrder->id,
            'user_id' => $user->id,
            'reason' => 'Apartment reopened due to roommate checkout or payment failure.',
            'status' => 'pending'
        ]);

        return response()->json([
            'message' => 'Refund request submitted successfully.',
            'data' => $refundRequest
        ], Response::HTTP_CREATED);
    }

    /**
     * Admin approves a refund request (POST /api/admin/refund-requests/{id}/approve)
     */
    public function approveRefund(Request $request, $id)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            abort(403, 'Unauthorized.');
        }

        $refundRequest = RefundRequest::findOrFail($id);
        if ($refundRequest->status !== 'pending') {
            return response()->json([
                'message' => 'Refund request is already processed.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $paymentOrder = $refundRequest->paymentOrder;

        // Find the transaction ID to refund
        $chargeTx = Transaction::where('payment_order_id', $paymentOrder->id)
            ->where('type', 'charge')
            ->where('status', 'success')
            ->first();

        if (!$chargeTx || !$chargeTx->paymob_transaction_id) {
            return response()->json([
                'message' => 'Associated Paymob transaction not found.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Skip Paymob refund API call for dummy/sandbox payments
        $isDummyPayment = str_starts_with($chargeTx->paymob_transaction_id, 'dummy_');

        if (!$isDummyPayment) {
            // Call Paymob refund API for real payments
            $refundResponse = $this->paymobService->refund(
                $chargeTx->paymob_transaction_id,
                $paymentOrder->amount_cents
            );

            if (!$refundResponse) {
                return response()->json([
                    'message' => 'Failed to process refund through Paymob API.'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        DB::transaction(function () use ($refundRequest, $paymentOrder, $chargeTx) {
            // Update RefundRequest
            $refundRequest->update([
                'status' => 'approved',
                'processed_at' => now(),
            ]);

            // Update PaymentOrder status to refunded
            $paymentOrder->update(['status' => 'refunded']);

            // Create Transaction record for the refund
            Transaction::create([
                'payment_order_id' => $paymentOrder->id,
                'user_id' => $paymentOrder->user_id,
                'apartment_id' => $paymentOrder->apartment_id,
                'type' => 'refund',
                'direction' => 'out',
                'amount_cents' => $paymentOrder->amount_cents,
                'currency' => 'EGP',
                'paymob_transaction_id' => 'ref_' . uniqid(),
                'status' => 'success',
                'metadata' => $chargeTx->metadata,
            ]);

            // Remove/Cancel member from the apartment membership list
            $member = ApartmentMember::where('apartment_id', $paymentOrder->apartment_id)
                ->where('user_id', $paymentOrder->user_id)
                ->first();

            if ($member) {
                $member->update(['membership_status' => 'cancelled']);

                // Cancel all unpaid payment orders for this user in this apartment
                PaymentOrder::where('user_id', $paymentOrder->user_id)
                    ->where('apartment_id', $paymentOrder->apartment_id)
                    ->whereIn('status', ['pending', 'unpaid'])
                    ->update(['status' => 'cancelled']);

                // Decrement correct gender counter on the apartment
                $apartment = Apartment::find($paymentOrder->apartment_id);
                if ($member->gender_snapshot === 'male') {
                    if ($apartment->male_count > 0) $apartment->male_count--;
                } else {
                    if ($apartment->female_count > 0) $apartment->female_count--;
                }
                $apartment->save();

                // Clear caches
                Cache::forget("apartment_details_{$apartment->id}");
                Cache::forget("apartments_list_gender_male");
                Cache::forget("apartments_list_gender_female");
                Cache::forget("apartments_list_gender_any");
            }

            // Notify user
            $this->notifyUser(
                $paymentOrder->user_id,
                "Refund Approved",
                "Your refund request for EGP " . ($paymentOrder->amount_cents / 100) . " has been approved and processed.",
                ['type' => 'refund_processed', 'status' => 'approved', 'apartment_id' => $paymentOrder->apartment_id]
            );
        });

        return response()->json([
            'message' => 'Refund request approved and processed successfully.',
            'data' => $refundRequest
        ]);
    }

    /**
     * Admin rejects a refund request (POST /api/admin/refund-requests/{id}/reject)
     */
    public function rejectRefund(Request $request, $id)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            abort(403, 'Unauthorized.');
        }

        $refundRequest = RefundRequest::findOrFail($id);
        if ($refundRequest->status !== 'pending') {
            return response()->json([
                'message' => 'Refund request is already processed.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $refundRequest->update([
            'status' => 'rejected',
            'processed_at' => now(),
        ]);

        $this->notifyUser(
            $refundRequest->user_id,
            "Refund Rejected",
            "Your refund request has been reviewed and rejected.",
            ['type' => 'refund_processed', 'status' => 'rejected', 'apartment_id' => $refundRequest->paymentOrder->apartment_id]
        );

        return response()->json([
            'message' => 'Refund request rejected.',
            'data' => $refundRequest
        ]);
    }

    /**
     * Tenant checks their payment status (GET /api/tenant/payment-status)
     */
    public function getPaymentStatus(Request $request)
    {
        $user = $request->user();

        // Get latest PaymentOrder for this user
        $order = PaymentOrder::with('apartment')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'No payment order found for this user.'
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $order
        ]);
    }

    /** GET /api/payment/orders */
    public function listOrders(Request $request)
    {
        $orders = PaymentOrder::where('user_id', $request->user()->id)
            ->with(['apartment', 'refundRequest'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($order) {
                $arr = $order->toArray();
                $arr['has_refund_request'] = $order->refundRequest !== null;
                $arr['refund_status']      = $order->refundRequest?->status;
                return $arr;
            });
        return response()->json(['data' => $orders]);
    }

    /** GET /api/payment/orders/{id} */
    public function showOrder(Request $request, $id)
    {
        $order = PaymentOrder::where('user_id', $request->user()->id)
            ->with(['apartment', 'rentCycle'])
            ->findOrFail($id);
        return response()->json(['data' => $order]);
    }

    /**
     * POST /api/payment/orders/{id}/sync
     * Reconcile a pending order with Paymob when webhook/redirect was missed (e.g. mobile browser).
     */
    public function syncOrderFromPaymob(Request $request, $id)
    {
        $order = PaymentOrder::where('user_id', $request->user()->id)
            ->with(['apartment', 'rentCycle'])
            ->findOrFail($id);

        if ($order->status === 'paid') {
            return response()->json([
                'message' => 'Order already paid.',
                'data'    => $order,
            ]);
        }

        if (empty($order->paymob_order_id)) {
            return response()->json(
                ['message' => 'This order is not linked to Paymob.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $inquiry = $this->paymobService->inquireOrderTransactions((int) $order->paymob_order_id);
        $txn     = $this->paymobService->pickSuccessfulTransaction($inquiry, (int) $order->amount_cents);

        if (!$txn) {
            return response()->json([
                'message' => 'No successful Paymob transaction found for this order yet.',
                'data'    => $order,
            ]);
        }

        $paymobTxnId = isset($txn['id']) ? (string) $txn['id'] : null;
        $amountCents = (int) ($txn['amount_cents'] ?? $order->amount_cents);

        DB::transaction(function () use ($order, $paymobTxnId, $amountCents, $txn) {
            $this->recordSuccessfulPayment($order, $paymobTxnId, $amountCents, $txn);
        });

        Log::info("Payment sync: marked order {$order->id} paid via Paymob inquiry (paymob_order_id={$order->paymob_order_id}).");

        return response()->json([
            'message' => 'Payment synced successfully.',
            'data'    => $order->fresh(['apartment', 'rentCycle']),
        ]);
    }

    /** GET /api/payment/transactions */
    public function listTransactions(Request $request)
    {
        $userId = $request->user()->id;
        // Collect order IDs that already have a refund request
        $refundedOrderIds = RefundRequest::where('user_id', $userId)
            ->pluck('payment_order_id')
            ->toArray();

        $txns = Transaction::where('user_id', $userId)
            ->with('apartment')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($txn) use ($refundedOrderIds) {
                $arr = $txn->toArray();
                $arr['has_refund_request'] = in_array($txn->payment_order_id, $refundedOrderIds);
                return $arr;
            });
        return response()->json(['data' => $txns]);
    }

    /** GET /api/payment/refund-requests — tenant's own */
    public function listMyRefunds(Request $request)
    {
        $refunds = RefundRequest::where('user_id', $request->user()->id)
            ->with('paymentOrder.apartment')
            ->orderByDesc('created_at')
            ->get();
        return response()->json(['data' => $refunds]);
    }

    /** POST /api/payment/refund-requests — tenant submits with reason */
    public function submitRefund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_order_id' => 'required',
            'reason'           => 'required|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user  = $request->user();
        $order = PaymentOrder::where('id', $request->input('payment_order_id'))
            ->where('user_id', $user->id)
            ->first();
        if (!$order) {
            return response()->json(['message' => 'Payment order not found or not yours.'], Response::HTTP_NOT_FOUND);
        }
        if (RefundRequest::where('payment_order_id', $order->id)->exists()) {
            return response()->json(['message' => 'Refund request already submitted.']);
        }
        $refundRequest = RefundRequest::create([
            'payment_order_id' => $order->id,
            'user_id'          => $user->id,
            'reason'           => $request->input('reason'),
            'status'           => 'pending',
        ]);
        return response()->json(['message' => 'Refund request submitted.', 'data' => $refundRequest], Response::HTTP_CREATED);
    }

    /**
     * Dummy pay — marks order as paid and activates membership without Paymob.
     * POST /api/payment/orders/{id}/dummy-pay
     */
    public function dummyPay(Request $request, $id)
    {
        $order = PaymentOrder::where('user_id', $request->user()->id)->findOrFail($id);

        if ($order->status === 'paid') {
            return response()->json(['message' => 'Order already paid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($order) {
            $order->update(['status' => 'paid', 'paid_at' => now()]);

            Transaction::firstOrCreate(
                ['paymob_transaction_id' => 'dummy_' . $order->id],
                [
                    'payment_order_id'      => $order->id,
                    'user_id'               => $order->user_id,
                    'apartment_id'          => $order->apartment_id,
                    'type'                  => 'charge',
                    'direction'             => 'in',
                    'amount_cents'          => $order->amount_cents,
                    'currency'              => 'EGP',
                    'status'                => 'success',
                    'metadata'              => ['source' => 'dummy_pay'],
                ]
            );

            $breakdown = $order->breakdown;

            if (($breakdown['insurance_cents'] ?? 0) > 0) {
                InsurancePayment::firstOrCreate(
                    ['user_id' => $order->user_id, 'apartment_id' => $order->apartment_id],
                    ['payment_order_id' => $order->id, 'amount_cents' => $breakdown['insurance_cents'], 'paid_at' => now()]
                );
            }

            ApartmentMember::where('apartment_id', $order->apartment_id)
                ->where('user_id', $order->user_id)
                ->update(['membership_status' => 'active']);

            $rentCycle   = $order->rentCycle;
            $apartmentId = $order->apartment_id;

            $allCycleOrders = $rentCycle
                ? PaymentOrder::where('rent_cycle_id', $rentCycle->id)->get()
                : collect();
            $unpaidCount = $allCycleOrders->whereNotIn('status', ['paid'])->count();

            if ($unpaidCount === 0 && $rentCycle) {
                $apartment      = Apartment::find($apartmentId);
                if (!$apartment) {
                    return;
                }
                $durationMonths = $apartment->rent_duration ?? 1;

                $rentCycle->update([
                    'status'    => 'active',
                    'starts_at' => now(),
                    'ends_at'   => now()->addMonths($durationMonths),
                ]);

                $this->markApartmentRented($apartment);

                Cache::forget("apartment_details_{$apartmentId}");
                Cache::forget('apartments_list_gender_male');
                Cache::forget('apartments_list_gender_female');
                Cache::forget('apartments_list_gender_any');

                // 50/50 payout split
                $totalRentCents       = $allCycleOrders->sum(fn($o) => $o->breakdown['rent_cents'] ?? 0);
                $ownerPayoutCents     = (int) floor($totalRentCents * 0.50);
                $adminCommissionCents = $totalRentCents - $ownerPayoutCents;

                $owner = $apartment->owner;

                $payoutStatus   = 'pending_manual';
                $payoutMetadata = ['description' => 'Owner rent payout (50%) via dummy pay', 'source' => 'dummy_pay'];

                if ($owner && $owner->payout_type === 'wallet' && !empty($owner->payout_number)) {
                    $paymobResponse = $this->paymobService->payoutToWallet($owner->payout_number, $ownerPayoutCents);
                    if ($paymobResponse) {
                        $payoutStatus = 'success';
                        $payoutMetadata['paymob_response'] = $paymobResponse;
                    } else {
                        $payoutMetadata['error'] = 'Paymob payout failed; will require manual processing.';
                    }
                } else {
                    $payoutMetadata['note'] = 'Manual/bank payout method. Admin must process manually.';
                }

                Transaction::create([
                    'payment_order_id' => null,
                    'user_id'          => $apartment->owner_id,
                    'apartment_id'     => $apartmentId,
                    'type'             => 'payout_owner',
                    'direction'        => 'out',
                    'amount_cents'     => $ownerPayoutCents,
                    'currency'         => 'EGP',
                    'status'           => $payoutStatus,
                    'metadata'         => $payoutMetadata,
                ]);

                Transaction::create([
                    'payment_order_id' => null,
                    'user_id'          => null,
                    'apartment_id'     => $apartmentId,
                    'type'             => 'admin_commission',
                    'direction'        => 'in',
                    'amount_cents'     => $adminCommissionCents,
                    'currency'         => 'EGP',
                    'status'           => 'success',
                    'metadata'         => ['description' => 'Admin commission (50% of rent)', 'source' => 'dummy_pay', 'total_rent_cents' => $totalRentCents],
                ]);

                $ownerName = trim(($owner->profile?->first_name ?? '') . ' ' . ($owner->profile?->last_name ?? '')) ?: 'Owner';

                foreach ($allCycleOrders as $o) {
                    $this->notifyUser($o->user_id, 'Payment Complete - Rent Started',
                        'All roommates have paid. You can now view the exact location and owner contact info.', [
                            'type'         => 'payment_completed',
                            'apartment_id' => $apartmentId,
                            'latitude'     => $apartment->latitude,
                            'longitude'    => $apartment->longitude,
                            'owner_name'   => $ownerName,
                            'owner_phone'  => $owner->phone ?? 'NA',
                            'owner_email'  => $owner->email ?? 'NA',
                        ]);
                }
            }
        });

        return response()->json(['message' => 'Payment recorded successfully.', 'data' => $order->fresh()]);
    }

    /**
     * POST /api/admin/payment/orders — admin creates a custom payment order for a user.
     */
    public function adminCreateOrder(Request $request)
    {
        if (!$request->user()->isAdmin()) abort(403);

        $validated = $request->validate([
            'user_id'      => 'required|exists:users,id',
            'amount_cents' => 'required|integer|min:100',
            'description'  => 'nullable|string|max:255',
            'apartment_id' => 'nullable|exists:apartments,id',
        ]);

        $user        = \App\Models\User::findOrFail($validated['user_id']);
        $amountCents = (int) $validated['amount_cents'];
        $description = $validated['description'] ?? 'Custom payment order';
        $apartmentId = $validated['apartment_id'] ?? null;

        $breakdown = ['custom_cents' => $amountCents, 'description' => $description];

        // Create Paymob order
        $token = $this->paymobService->getAuthToken();
        $paymobOrderId  = null;
        $paymobKey      = null;
        $paymentUrl     = null;

        if ($token) {
            $merchantOrderId = 'admin_custom_' . \Str::uuid();
            $paymobOrderId = $this->paymobService->createOrder($token, $amountCents, $merchantOrderId);
            if ($paymobOrderId) {
                $billingData = [
                    'first_name'   => $user->profile?->first_name ?? 'User',
                    'last_name'    => $user->profile?->last_name  ?? '',
                    'email'        => $user->email        ?? 'user@example.com',
                    'phone_number' => $user->phone        ?? '01000000000',
                ];
                $paymobKey  = $this->paymobService->createPaymentKey($token, $paymobOrderId, $amountCents, $billingData);
                $paymentUrl = $paymobKey ? $this->paymobService->getPaymentUrl($paymobKey) : null;
            }
        }

        $order = PaymentOrder::create([
            'idempotency_key'    => 'admin_custom_' . \Str::uuid(),
            'user_id'            => $user->id,
            'apartment_id'       => $apartmentId,
            'rent_cycle_id'      => null,
            'amount_cents'       => $amountCents,
            'breakdown'          => $breakdown,
            'paymob_order_id'    => $paymobOrderId !== null ? (string) $paymobOrderId : null,
            'paymob_payment_key' => $paymobKey,
            'payment_url'        => $paymentUrl,
            'status'             => 'pending',
            'expires_at'         => now()->addDays(7),
        ]);

        $this->notifyUser($user->id, 'New Payment Required', "You have a new payment of EGP " . number_format($amountCents / 100, 2) . ". Reason: $description", [
            'type'             => 'custom_payment_order',
            'payment_order_id' => $order->id,
        ]);

        return response()->json(['message' => 'Payment order created.', 'data' => $order], Response::HTTP_CREATED);
    }

    /** POST /api/payment/orders/{id}/retry */
    public function retryPaymentLink(Request $request, $id)
    {
        $order = PaymentOrder::where('user_id', $request->user()->id)->findOrFail($id);

        if ($order->status === 'paid') {
            return response()->json(['message' => 'Order already paid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Regenerate a fresh Paymob payment key so the link is not stale
        $token = $this->paymobService->getAuthToken();
        if ($token && $order->paymob_order_id) {
            $user = $order->user;
            $billingData = [
                'first_name'   => $user->profile?->first_name ?? 'Tenant',
                'last_name'    => $user->profile?->last_name  ?? 'User',
                'email'        => $user->email  ?? 'tenant@example.com',
                'phone_number' => $user->phone  ?? '01000000000',
            ];
            $newKey = $this->paymobService->createPaymentKey(
                $token,
                $order->paymob_order_id,
                $order->amount_cents,
                $billingData
            );
            if ($newKey) {
                $newUrl = $this->paymobService->getPaymentUrl($newKey);
                $order->update([
                    'paymob_payment_key' => $newKey,
                    'payment_url'        => $newUrl,
                    'status'             => 'pending',
                ]);
            }
        }

        return response()->json([
            'message' => 'Payment link refreshed.',
            'data'    => $order->fresh(),
        ]);
    }

    /** GET /api/admin/refund-requests — admin lists all */
    public function listAllRefunds(Request $request)
    {
        if (!$request->user() || !$request->user()->isAdmin()) abort(403);
        $refunds = RefundRequest::with(['user.profile', 'paymentOrder.apartment'])
            ->orderByDesc('created_at')
            ->get();
        return response()->json(['data' => $refunds]);
    }

    /** GET /api/owners */
    public function listOwners(Request $request)
    {
        $owners = \App\Models\User::whereHas('roles', fn($q) => $q->where('role', 'owner'))
            ->with('profile')
            ->get();
        return response()->json(['data' => $owners]);
    }

    /**
     * Paymob Transaction Response Redirect (GET /acceptance/post_pay)
     * Handles the browser redirect after the iframe payment completes.
     * Acts as a fallback in case the webhook was delayed or missed.
     */
    public function handlePostPay(Request $request)
    {
        $payload = $request->all();
        $hmac    = $request->query('hmac');

        if (!$hmac) {
            return $this->showPaymentStatusView(false, 'Missing secure signature.');
        }

        // Paymob sends flat query params; rebuild nested source_data for HMAC verification
        $obj = $payload;
        $pan     = $payload['source_data_pan']      ?? $payload['source_data.pan']      ?? null;
        $subType = $payload['source_data_sub_type'] ?? $payload['source_data.sub_type'] ?? null;
        $type    = $payload['source_data_type']     ?? $payload['source_data.type']     ?? null;
        if ($pan     !== null) $obj['source_data']['pan']      = $pan;
        if ($subType !== null) $obj['source_data']['sub_type'] = $subType;
        if ($type    !== null) $obj['source_data']['type']     = $type;

        if (!$this->paymobService->verifyHmac($obj, $hmac)) {
            Log::warning('Paymob PostPay: HMAC mismatch.');
            return $this->showPaymentStatusView(false, 'Payment verification failed (signature mismatch).');
        }

        $success         = filter_var($obj['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $paymobOrderId   = $obj['order']['id'] ?? ($obj['order'] ?? null);
        $paymobTxnId     = $obj['id'] ?? null;
        $amountCents     = $obj['amount_cents'] ?? 0;

        if (!$paymobOrderId) {
            return $this->showPaymentStatusView(false, 'Missing Paymob Order ID.');
        }

        $paymentOrder = PaymentOrder::findByPaymobOrderId($paymobOrderId);
        if (!$paymentOrder) {
            Log::warning("Paymob PostPay: PaymentOrder not found for Paymob Order ID: {$paymobOrderId}");
            return $this->showPaymentStatusView(false, 'Payment order not found in our system.');
        }

        if ($success) {
            if ($paymentOrder->status !== 'paid') {
                try {
                    DB::transaction(function () use ($paymentOrder, $paymobTxnId, $amountCents, $obj) {
                        $this->recordSuccessfulPayment($paymentOrder, $paymobTxnId, $amountCents, $obj);
                    });
                } catch (\Exception $e) {
                    Log::error('Error in handlePostPay redirect processing: ' . $e->getMessage());
                    return $this->showPaymentStatusView(false, 'An error occurred while updating your payment record.');
                }
            }
            return $this->showPaymentStatusView(true, 'Your payment has been successfully processed.', $paymentOrder);
        }

        // Failed transaction
        try {
            DB::transaction(function () use ($paymentOrder, $paymobTxnId, $amountCents, $obj) {
                Transaction::firstOrCreate(
                    ['paymob_transaction_id' => $paymobTxnId],
                    [
                        'payment_order_id' => $paymentOrder->id,
                        'user_id'          => $paymentOrder->user_id,
                        'apartment_id'     => $paymentOrder->apartment_id,
                        'type'             => 'charge',
                        'direction'        => 'in',
                        'amount_cents'     => $amountCents,
                        'currency'         => 'EGP',
                        'status'           => 'failed',
                        'metadata'         => $obj,
                    ]
                );
                if ($paymentOrder->status === 'pending') {
                    $paymentOrder->update(['status' => 'failed']);
                }
            });
        } catch (\Exception $e) {
            Log::error('Error recording failed payment in handlePostPay: ' . $e->getMessage());
        }

        return $this->showPaymentStatusView(false, 'Transaction was declined or failed. Please try again.', $paymentOrder);
    }

    /**
     * Shared logic for webhook, post_pay redirect, and manual Paymob sync.
     */
    protected function recordSuccessfulPayment(
        PaymentOrder $paymentOrder,
        mixed $paymobTransactionId,
        int $amountCents,
        array $metadata
    ): void {
        if ($paymentOrder->status !== 'paid') {
            $paymentOrder->update([
                'status'  => 'paid',
                'paid_at' => now(),
            ]);
        }

        if ($paymobTransactionId) {
            Transaction::firstOrCreate(
                ['paymob_transaction_id' => (string) $paymobTransactionId],
                [
                    'payment_order_id' => $paymentOrder->id,
                    'user_id'          => $paymentOrder->user_id,
                    'apartment_id'     => $paymentOrder->apartment_id,
                    'type'             => 'charge',
                    'direction'        => 'in',
                    'amount_cents'     => $amountCents,
                    'currency'         => 'EGP',
                    'status'           => 'success',
                    'metadata'         => $metadata,
                ]
            );
        }

        $breakdown      = $paymentOrder->breakdown;
        $insuranceCents = $breakdown['insurance_cents'] ?? 0;
        if ($insuranceCents > 0) {
            InsurancePayment::firstOrCreate(
                [
                    'user_id'      => $paymentOrder->user_id,
                    'apartment_id' => $paymentOrder->apartment_id,
                ],
                [
                    'payment_order_id' => $paymentOrder->id,
                    'amount_cents'     => $insuranceCents,
                    'paid_at'          => now(),
                ]
            );
        }

        $platformFeeCents = $breakdown['platform_fee_cents'] ?? 0;
        if ($platformFeeCents > 0) {
            $paymentOrder->user->update(['has_paid_platform_fee' => true]);
        }

        ApartmentMember::where('apartment_id', $paymentOrder->apartment_id)
            ->where('user_id', $paymentOrder->user_id)
            ->update(['membership_status' => 'active']);

        $rentCycle   = $paymentOrder->rentCycle;
        $apartmentId = $paymentOrder->apartment_id;

        if (!$rentCycle) {
            Log::warning("recordSuccessfulPayment: PaymentOrder {$paymentOrder->id} has no associated RentCycle.");
            return;
        }

        $allCycleOrders = PaymentOrder::where('rent_cycle_id', $rentCycle->id)->get();
        $unpaidCount    = $allCycleOrders->whereNotIn('status', ['paid'])->count();

        if ($unpaidCount !== 0) {
            return;
        }

        $apartment      = Apartment::find($apartmentId);
        if (!$apartment) {
            return;
        }

        $durationMonths = $apartment->rent_duration ?? 1;

        $rentCycle->update([
            'status'    => 'active',
            'starts_at' => now(),
            'ends_at'   => now()->addMonths($durationMonths),
        ]);

        $this->markApartmentRented($apartment);

        Cache::forget("apartment_details_{$apartmentId}");
        Cache::forget('apartments_list_gender_male');
        Cache::forget('apartments_list_gender_female');
        Cache::forget('apartments_list_gender_any');

        $totalRentCents       = $allCycleOrders->sum(fn ($o) => $o->breakdown['rent_cents'] ?? 0);
        $ownerPayoutCents     = (int) floor($totalRentCents * 0.50);
        $adminCommissionCents = $totalRentCents - $ownerPayoutCents;

        $owner          = $apartment->owner;
        $payoutStatus   = 'failed';
        $payoutMetadata = ['description' => 'Owner monthly rent payout (50% of rent)'];

        if ($owner && $owner->payout_type === 'wallet' && !empty($owner->payout_number)) {
            $paymobResponse = $this->paymobService->payoutToWallet($owner->payout_number, $ownerPayoutCents);
            if ($paymobResponse) {
                $payoutStatus = 'success';
                $payoutMetadata['paymob_response'] = $paymobResponse;
            } else {
                Log::error("Owner Payout failed via Paymob for Owner ID: {$owner->id}");
                $payoutMetadata['error'] = 'Paymob payout API call failed';
            }
        } else {
            $payoutStatus = 'pending_manual';
            $payoutMetadata['note'] = 'Manual or bank transfer payout method (non-wallet). Admin must process manually.';
        }

        Transaction::create([
            'payment_order_id' => null,
            'user_id'          => $apartment->owner_id,
            'apartment_id'     => $apartmentId,
            'type'             => 'payout_owner',
            'direction'        => 'out',
            'amount_cents'     => $ownerPayoutCents,
            'currency'         => 'EGP',
            'status'           => $payoutStatus,
            'metadata'         => $payoutMetadata,
        ]);

        Transaction::create([
            'payment_order_id' => null,
            'user_id'          => null,
            'apartment_id'     => $apartmentId,
            'type'             => 'admin_commission',
            'direction'        => 'in',
            'amount_cents'     => $adminCommissionCents,
            'currency'         => 'EGP',
            'status'           => 'success',
            'metadata'         => [
                'description'      => 'Admin commission (50% of rent)',
                'total_rent_cents' => $totalRentCents,
            ],
        ]);

        $ownerName   = trim(($owner->profile?->first_name ?? '') . ' ' . ($owner->profile?->last_name ?? '')) ?: 'Owner';
        $payoutTitle = 'Payment Complete - Rent Started';
        $payoutBody  = 'All roommates have paid. You can now view the exact location and owner contact info.';

        foreach ($allCycleOrders as $o) {
            $this->notifyUser($o->user_id, $payoutTitle, $payoutBody, [
                'type'         => 'payment_completed',
                'apartment_id' => $apartmentId,
                'latitude'     => $apartment->latitude,
                'longitude'    => $apartment->longitude,
                'owner_name'   => $ownerName,
                'owner_phone'  => $owner->phone ?? 'NA',
                'owner_email'  => $owner->email ?? 'NA',
            ]);
        }
    }

    /**
     * Render a glassmorphic HTML feedback page for post-payment redirect.
     */
    protected function showPaymentStatusView(bool $success, string $message, ?PaymentOrder $paymentOrder = null)
    {
        $statusClass = $success ? 'success' : 'error';
        $titleText   = $success ? 'Payment Successful!' : 'Payment Failed';
        $iconSvg     = $success
            ? '<svg class="checkmark" viewBox="0 0 52 52"><circle cx="26" cy="26" r="25" fill="none"/><path d="M14.1 27.2l7.1 7.2 16.7-16.8"/></svg>'
            : '<svg class="cross"     viewBox="0 0 52 52"><circle cx="26" cy="26" r="25" fill="none"/><path d="M16 16l20 20M36 16L16 36"/></svg>';

        $detailsHtml = '';
        if ($paymentOrder) {
            $formattedAmount = number_format($paymentOrder->amount_cents / 100, 2) . ' EGP';
            $orderId         = $paymentOrder->paymob_order_id ?? 'N/A';
            $date            = $paymentOrder->updated_at
                ? $paymentOrder->updated_at->timezone('Africa/Cairo')->format('Y-m-d H:i')
                : now()->timezone('Africa/Cairo')->format('Y-m-d H:i');
            $tenantName      = $paymentOrder->user->name ?? $paymentOrder->user->email ?? 'Tenant';
            $detailsHtml = "
            <div class=\"details-list\">
                <div class=\"details-item\"><span class=\"details-label\">Tenant</span><span class=\"details-value\">{$tenantName}</span></div>
                <div class=\"details-item\"><span class=\"details-label\">Order ID</span><span class=\"details-value\">{$orderId}</span></div>
                <div class=\"details-item\"><span class=\"details-label\">Amount Paid</span><span class=\"details-value\">{$formattedAmount}</span></div>
                <div class=\"details-item\"><span class=\"details-label\">Date &amp; Time</span><span class=\"details-value\">{$date}</span></div>
            </div>";
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status - Sukoon</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #311042 100%);
            --card-bg: rgba(30, 41, 59, 0.7);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --success-color: #10b981;
            --success-glow: rgba(16, 185, 129, 0.2);
            --error-color: #ef4444;
            --error-glow: rgba(239, 68, 68, 0.2);
            --accent-gradient: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Outfit', sans-serif; }
        body { background: var(--bg-gradient); min-height: 100vh; display: flex; align-items: center; justify-content: center; color: var(--text-primary); padding: 20px; overflow-x: hidden; }
        .container { width: 100%; max-width: 500px; }
        .card { background: var(--card-bg); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid var(--card-border); border-radius: 24px; padding: 40px 30px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.1); animation: slideUp 0.8s cubic-bezier(0.16,1,0.3,1) forwards; opacity: 0; transform: translateY(40px); }
        @keyframes slideUp { to { opacity: 1; transform: translateY(0); } }
        .icon-container { width: 80px; height: 80px; margin: 0 auto 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .icon-container.success { background: rgba(16,185,129,0.1); border: 2px solid var(--success-color); box-shadow: 0 0 20px var(--success-glow); animation: pulseS 2s infinite; }
        .icon-container.error   { background: rgba(239,68,68,0.1); border: 2px solid var(--error-color); box-shadow: 0 0 20px var(--error-glow); animation: pulseE 2s infinite; }
        @keyframes pulseS { 0%,100%{box-shadow:0 0 15px rgba(16,185,129,0.2)} 50%{box-shadow:0 0 25px rgba(16,185,129,0.4)} }
        @keyframes pulseE { 0%,100%{box-shadow:0 0 15px rgba(239,68,68,0.2)} 50%{box-shadow:0 0 25px rgba(239,68,68,0.4)} }
        .status-title { font-size: 28px; font-weight: 800; margin-bottom: 12px; letter-spacing: -0.5px; }
        .status-title.success { color: #34d399; }
        .status-title.error   { color: #f87171; }
        .status-message { color: var(--text-secondary); font-size: 16px; line-height: 1.6; margin-bottom: 32px; font-weight: 300; }
        .details-list { background: rgba(15,23,42,0.3); border-radius: 16px; padding: 20px; margin-bottom: 32px; border: 1px solid rgba(255,255,255,0.04); text-align: left; }
        .details-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .details-item:last-child { border-bottom: none; }
        .details-label { color: var(--text-secondary); font-size: 14px; }
        .details-value { color: var(--text-primary); font-weight: 600; font-size: 14px; }
        .btn { display: inline-block; width: 100%; padding: 16px; border-radius: 14px; font-size: 16px; font-weight: 600; color: white; background: var(--accent-gradient); border: none; cursor: pointer; box-shadow: 0 8px 20px rgba(99,102,241,0.3); transition: all 0.3s cubic-bezier(0.16,1,0.3,1); }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(99,102,241,0.45); }
        .checkmark,.cross { width: 40px; height: 40px; stroke-width: 4; stroke-linecap: round; stroke-linejoin: round; fill: none; stroke-dasharray: 100; stroke-dashoffset: 100; animation: draw 0.6s cubic-bezier(0.16,1,0.3,1) 0.2s forwards; }
        .checkmark,.checkmark circle { stroke: var(--success-color); }
        .cross,.cross circle { stroke: var(--error-color); }
        .cross { width: 32px; height: 32px; }
        @keyframes draw { to { stroke-dashoffset: 0; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="icon-container {$statusClass}">{$iconSvg}</div>
            <h1 class="status-title {$statusClass}">{$titleText}</h1>
            <p class="status-message">{$message}</p>
            {$detailsHtml}
            <button class="btn" onclick="tryClose()">Return to App</button>
        </div>
    </div>
    <script>
        function tryClose() {
            if (window.ReactNativeWebView) {
                window.ReactNativeWebView.postMessage(JSON.stringify({ status: '{$statusClass}' }));
            } else if (window.parent && window.parent !== window) {
                window.parent.postMessage(JSON.stringify({ status: '{$statusClass}' }), '*');
            } else {
                window.close();
                setTimeout(function() { alert('Payment complete. You can now close this tab and return to the Sukoon app.'); }, 500);
            }
        }
    </script>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html');
    }
}
