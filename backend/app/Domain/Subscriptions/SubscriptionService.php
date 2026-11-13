<?php

namespace App\Domain\Subscriptions;

use App\Domain\Subscriptions\Models\Subscription;
use App\Domain\Subscriptions\Models\SubscriptionPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Starting, confirming, cancelling and renewing subscriptions. Money is handled only through Razorpay. */
class SubscriptionService
{
    public function __construct(private RazorpayClient $razorpay) {}

    /** The row that belongs to this person's subscriber (their creator account, or their company), if it exists. */
    public function subscriptionFor(User $user): ?Subscription
    {
        return Entitlements::subscriptionOf($user);
    }

    /**
     * Opens a Razorpay subscription and returns what Checkout needs to take the first payment.
     *
     * @return array<string, mixed>
     */
    public function startCheckout(User $user): array
    {
        if (! $this->razorpay->configured()) {
            throw new RuntimeException('Payments are not set up yet. Please try again later.');
        }

        $existing = $this->subscriptionFor($user);
        if ($existing && $existing->status === Subscription::ACTIVE && $existing->grantsAccess() && ! $existing->cancel_at_period_end) {
            throw ValidationException::withMessages(['subscription' => ['You already have an active subscription.']]);
        }

        $subscriber = $this->subscriberColumns($user);
        $created = $this->razorpay->createSubscription(['for' => $subscriber['label']]);

        $subscription = Subscription::updateOrCreate($subscriber['where'], [
            'status' => Subscription::CREATED,
            'gateway' => 'razorpay',
            'gateway_subscription_id' => $created['id'],
            'price_minor' => SubscriptionPricing::priceMinor(),
            'gst_minor' => SubscriptionPricing::gstMinor(),
            'total_minor' => SubscriptionPricing::totalMinor(),
            'currency' => config('subscription.currency'),
            'cancel_at_period_end' => false,
        ]);

        return [
            'key_id' => $this->razorpay->keyId(),
            'subscription_id' => $subscription->gateway_subscription_id,
            'name' => 'BrandMate',
            'description' => 'Monthly subscription, Rs 999 plus 18% GST',
            'prefill' => ['name' => $user->name, 'email' => $user->email, 'contact' => $user->phone],
        ];
    }

    /**
     * Called after Checkout reports a payment. The signature proves the payment is real and
     * belongs to this subscription; only then does access start. Safe to call twice.
     */
    public function confirmPayment(User $user, string $paymentId, string $subscriptionId, string $signature): Subscription
    {
        if (! $this->razorpay->paymentSignatureIsValid($paymentId, $subscriptionId, $signature)) {
            throw ValidationException::withMessages(['razorpay_signature' => ['The payment could not be verified.']]);
        }

        $subscription = $this->subscriptionFor($user);
        if (! $subscription || $subscription->gateway_subscription_id !== $subscriptionId) {
            throw ValidationException::withMessages(['razorpay_subscription_id' => ['This payment is not for your subscription.']]);
        }

        return DB::transaction(function () use ($subscription, $paymentId) {
            $start = CarbonImmutable::now();
            $subscription->forceFill([
                'status' => Subscription::ACTIVE,
                // The webhook corrects the period to Razorpay's own dates; this covers the gap until it arrives.
                'current_period_start' => $subscription->current_period_start ?? $start,
                'current_period_end' => $subscription->current_period_end?->isFuture() ? $subscription->current_period_end : $start->addMonth(),
            ])->save();

            $this->recordPayment($subscription, $paymentId, SubscriptionPricing::totalMinor());

            return $subscription->refresh();
        });
    }

    /** Stops renewals. What was paid for keeps working until the period ends. */
    public function cancel(User $user): Subscription
    {
        $subscription = $this->subscriptionFor($user);
        if (! $subscription || ! $subscription->grantsAccess()) {
            throw ValidationException::withMessages(['subscription' => ['There is no active subscription to cancel.']]);
        }

        if ($subscription->gateway === 'razorpay' && $subscription->gateway_subscription_id) {
            $this->razorpay->cancelSubscription($subscription->gateway_subscription_id);
        }
        $subscription->forceFill(['cancel_at_period_end' => true])->save();

        return $subscription;
    }

    /**
     * Applies one Razorpay webhook. Each event id is processed once, so retries are harmless.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(string $eventId, array $payload): void
    {
        $type = (string) ($payload['event'] ?? '');

        DB::transaction(function () use ($eventId, $type, $payload) {
            $already = DB::table('webhook_events')->where('event_id', $eventId)->lockForUpdate()->exists();
            if ($already) {
                return;
            }
            DB::table('webhook_events')->insert(['event_id' => $eventId, 'type' => $type, 'processed_at' => now()]);

            $entity = $payload['payload']['subscription']['entity'] ?? null;
            $subscription = $entity ? Subscription::where('gateway_subscription_id', $entity['id'] ?? '')->first() : null;
            if (! $subscription) {
                return; // not one of ours, or a payment with no subscription
            }

            match ($type) {
                'subscription.activated', 'subscription.charged' => $this->charged($subscription, $entity, $payload),
                'subscription.pending', 'subscription.halted' => $subscription->forceFill(['status' => Subscription::PAST_DUE])->save(),
                'subscription.cancelled' => $subscription->forceFill(['status' => Subscription::CANCELLED, 'cancel_at_period_end' => true])->save(),
                'subscription.completed', 'subscription.expired' => $subscription->forceFill(['status' => Subscription::EXPIRED])->save(),
                default => null,
            };
        });
    }

    /** @param array<string, mixed> $entity @param array<string, mixed> $payload */
    private function charged(Subscription $subscription, array $entity, array $payload): void
    {
        $subscription->forceFill([
            'status' => Subscription::ACTIVE,
            'current_period_start' => isset($entity['current_start']) ? CarbonImmutable::createFromTimestamp($entity['current_start']) : $subscription->current_period_start,
            'current_period_end' => isset($entity['current_end']) ? CarbonImmutable::createFromTimestamp($entity['current_end']) : $subscription->current_period_end,
        ])->save();

        $payment = $payload['payload']['payment']['entity'] ?? null;
        if ($payment && ($payment['status'] ?? null) === 'captured') {
            $this->recordPayment($subscription, (string) $payment['id'], (int) $payment['amount']);
        }
    }

    /** Records a charge once per Razorpay payment id and gives it an invoice number. */
    private function recordPayment(Subscription $subscription, string $paymentId, int $totalMinor): SubscriptionPayment
    {
        $existing = SubscriptionPayment::where('gateway_payment_id', $paymentId)->first();
        if ($existing) {
            return $existing;
        }

        $split = SubscriptionPricing::splitTotal($totalMinor);
        $payment = SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'gateway_payment_id' => $paymentId,
            'price_minor' => $split['price'],
            'gst_minor' => $split['gst'],
            'total_minor' => $split['total'],
            'currency' => $subscription->currency,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $payment->forceFill(['invoice_number' => sprintf('BM-%s-%06d', now()->format('Y'), $payment->id)])->save();

        return $payment;
    }

    /** @return array{where: array<string, int>, label: string} */
    private function subscriberColumns(User $user): array
    {
        if ($user->isCreator()) {
            return ['where' => ['user_id' => $user->id], 'label' => 'creator:'.$user->id];
        }

        $companyId = $user->membership?->company_id;
        if (! $companyId) {
            throw ValidationException::withMessages(['subscription' => ['Only creators and brand companies can subscribe.']]);
        }

        return ['where' => ['company_id' => $companyId], 'label' => 'company:'.$companyId];
    }
}
