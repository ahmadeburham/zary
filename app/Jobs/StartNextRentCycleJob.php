<?php

namespace App\Jobs;

use App\Models\Apartment;
use App\Models\ApartmentMember;
use App\Models\InsurancePayment;
use App\Models\PaymentOrder;
use App\Models\RentCycle;
use App\Models\Notification;
use App\Jobs\SendNotification;
use App\Services\PaymobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StartNextRentCycleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PaymobService $paymobService): void
    {
        Log::info('StartNextRentCycleJob: Running daily rent cycle rollover.');

        // Find all active cycles that have ended
        $completedCycles = RentCycle::where('status', 'active')
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($completedCycles as $cycle) {
            DB::transaction(function () use ($cycle, $paymobService) {
                $lockedCycle = RentCycle::lockForUpdate()->find($cycle->id);
                if (!$lockedCycle || $lockedCycle->status !== 'active') return;

                $lockedCycle->update(['status' => 'completed']);

                $apartment = Apartment::lockForUpdate()->find($lockedCycle->apartment_id);
                if (!$apartment || $apartment->status !== 'rented') return;

                $members = ApartmentMember::where('apartment_id', $apartment->id)
                    ->where('membership_status', 'active')
                    ->get();

                if ($members->isEmpty()) {
                    $apartment->update(['status' => 'open']);
                    return;
                }

                $N = $members->count();
                $deadline = now()->addHours(24);
                $cycleNumber = $lockedCycle->cycle_number + 1;

                $newCycle = RentCycle::create([
                    'apartment_id' => $apartment->id,
                    'cycle_number' => $cycleNumber,
                    'starts_at'    => now(),
                    'ends_at'      => $deadline,
                    'status'       => 'pending_payment',
                ]);

                $paymobToken = $paymobService->getAuthToken();

                foreach ($members as $member) {
                    $user = $member->user;
                    if (!$user) continue;

                    $idempotencyKey = 'pay_order_' . $newCycle->id . '_' . $user->id;

                    $hasPaidInsurance = InsurancePayment::where('user_id', $user->id)
                        ->where('apartment_id', $apartment->id)
                        ->exists();

                    $rentShareCents     = (int)(ceil($apartment->price / $N) * 100);
                    $insuranceCents     = $hasPaidInsurance ? 0 : (int)(ceil($apartment->insurance / $N) * 100);
                    $platformFeeCents   = $user->has_paid_platform_fee ? 0 : (int)(ceil(($apartment->price / 2) / $N) * 100);
                    $totalAmountCents   = $rentShareCents + $insuranceCents + $platformFeeCents;

                    $paymobOrderId   = null;
                    $paymobPaymentKey = null;
                    $paymentUrl       = null;

                    if ($paymobToken) {
                        $paymobOrderId = $paymobService->createOrder($paymobToken, $totalAmountCents, $idempotencyKey);
                        if ($paymobOrderId) {
                            $billingData = [
                                'first_name'   => $user->profile?->first_name ?? 'Tenant',
                                'last_name'    => $user->profile?->last_name  ?? 'User',
                                'email'        => $user->email ?? 'tenant@example.com',
                                'phone_number' => $user->phone ?? '01000000000',
                            ];
                            $paymobPaymentKey = $paymobService->createPaymentKey($paymobToken, $paymobOrderId, $totalAmountCents, $billingData);
                            if ($paymobPaymentKey) {
                                $paymentUrl = $paymobService->getPaymentUrl($paymobPaymentKey);
                            }
                        }
                    }

                    PaymentOrder::create([
                        'idempotency_key'   => $idempotencyKey,
                        'rent_cycle_id'     => $newCycle->id,
                        'apartment_id'      => $apartment->id,
                        'user_id'           => $user->id,
                        'amount_cents'      => $totalAmountCents,
                        'breakdown'         => [
                            'rent_cents'         => $rentShareCents,
                            'insurance_cents'    => $insuranceCents,
                            'platform_fee_cents' => $platformFeeCents,
                        ],
                        'paymob_order_id'   => $paymobOrderId !== null ? (string) $paymobOrderId : null,
                        'paymob_payment_key' => $paymobPaymentKey,
                        'payment_url'       => $paymentUrl,
                        'status'            => 'pending',
                        'expires_at'        => $deadline,
                    ]);

                    ApartmentMember::where('id', $member->id)
                        ->update(['membership_status' => 'pending', 'payment_deadline' => $deadline]);

                    Notification::create([
                        'user_id'    => $user->id,
                        'type'       => 'payment_required',
                        'dedupe_key' => 'cycle_' . $newCycle->id . '_' . $user->id,
                        'data'       => [
                            'title'        => 'Monthly Rent Due',
                            'body'         => 'Your monthly rent share is due. Please pay within 24 hours.',
                            'type'         => 'payment_required',
                            'apartment_id' => $apartment->id,
                            'payment_link' => $paymentUrl ?? '',
                            'amount_egp'   => $totalAmountCents / 100,
                            'deadline'     => $deadline->toIso8601String(),
                        ],
                        'status' => 'pending',
                    ]);

                    SendNotification::dispatch(
                        $user->id,
                        'Monthly Rent Due',
                        'Your monthly rent share is due. Please pay within 24 hours.',
                        [
                            'type'         => 'payment_required',
                            'apartment_id' => $apartment->id,
                            'payment_link' => $paymentUrl ?? '',
                            'amount_egp'   => $totalAmountCents / 100,
                        ],
                        ['fcm']
                    );
                }

                Log::info("StartNextRentCycleJob: Initiated cycle #{$cycleNumber} for apartment {$apartment->id}.");
            });
        }

        Log::info('StartNextRentCycleJob: Done. Processed ' . $completedCycles->count() . ' cycles.');
    }
}
