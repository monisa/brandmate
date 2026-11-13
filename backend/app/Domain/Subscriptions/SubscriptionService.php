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
     * Opens a Razorpay order for one more month and returns what Checkout needs to take the payment.
     * The order id is kept in gateway_subscription_id until the payment is confirmed.
     *
     * @return array<string, mixed>
     */
    public function startCheckout(User $user): array
    {
        if (! $this->razorpay->configured()) {
            throw new RuntimeException('Payments are not set up yet. Please try again later.');
        }

        $subscriber = $this->subscriberColumns($user);
        $existing = $this->subscriptionFor($user);
        $order = $this->razorpay->createOrder(
            SubscriptionPricing::totalMinor(), 'bm_'.str_replace(':', '', $subscriber['label']).'_'.now()->timestamp, ['for' => $subscriber['label']],
        );

        // Paying while still active simply adds a month, so an active row keeps its status and period.
        $subscription = Subscription::updateOrCreate($subscriber['where'], [
            'status' => $existing && $existing->grantsAccess() ? $existing->status : Subscription::CREATED,
            'gateway' => 'razorpay',
            'gateway_subscription_id' => $order['id'],
            'price_minor' => SubscriptionPricing::priceMinor(),
            'gst_minor' => SubscriptionPricing::gstMinor(),
            'total_minor' => SubscriptionPricing::totalMinor(),
            'currency' => config('subscription.currency'),
            'cancel_at_period_end' => false,
        ]);

        return [
            'key_id' => $this->razorpay->keyId(),
            'order_id' => $subscription->gateway_subscription_id,
            'amount' => SubscriptionPricing::totalMinor(),
            'currency' => config('subscription.currency'),
            'name' => 'BrandMate',
            'description' => 'One month, Rs 999 plus 18% GST',
            'prefill' => ['name' => $user->name, 'email' => $user->email, 'contact' => $user->phone],
        ];
    }

    /**
     * Called after Checkout reports a payment. The signature proves the payment is real and
     * belongs to this order; only then does access extend. Safe to call twice.
     */
    public function confirmPayment(User $user, string $orderId, string $paymentId, string $signature): Subscription
    {
        if (! $this->razorpay->paymentSignatureIsValid($orderId, $paymentId, $signature)) {
            throw ValidationException::withMessages(['razorpay_signature' => ['The payment could not be verified.']]);
        }

        $subscription = $this->subscriptionFor($user);
        if (! $subscription || $subscription->gateway_subscription_id !== $orderId) {
            // A retry after the payment was already applied changes nothing.
            if ($subscription && SubscriptionPayment::where('gateway_payment_id', $paymentId)->where('subscription_id', $subscription->id)->exists()) {
                return $subscription;
            }
            throw ValidationException::withMessages(['razorpay_order_id' => ['This payment is not for your subscription.']]);
        }

        return $this->applyPayment($subscription, $paymentId, SubscriptionPricing::totalMinor());
    }

    /**
     * Applies one Razorpay webhook. Each event id is processed once, so retries are harmless. Events
     * for orders that are not ours (the account is shared with other software) are ignored.
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

            if (! in_array($type, ['order.paid', 'payment.captured'], true)) {
                return;
            }

            $payment = $payload['payload']['payment']['entity'] ?? null;
            $orderId = $payment['order_id'] ?? ($payload['payload']['order']['entity']['id'] ?? null);
            if (! $payment || ! $orderId || ($payment['status'] ?? null) !== 'captured') {
                return;
            }

            $subscription = Subscription::where('gateway_subscription_id', $orderId)->first();
            if ($subscription) {
                $this->applyPayment($subscription, (string) $payment['id'], (int) $payment['amount']);
            }
        });
    }

    /** Adds one month, counted from the end of the current period when it is still running. Once per payment. */
    private function applyPayment(Subscription $subscription, string $paymentId, int $totalMinor): Subscription
    {
        return DB::transaction(function () use ($subscription, $paymentId, $totalMinor) {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->first() ?? $subscription;
            if (SubscriptionPayment::where('gateway_payment_id', $paymentId)->exists()) {
                return $locked;
            }

            $now = CarbonImmutable::now();
            $running = $locked->grantsAccess() && $locked->current_period_end !== null;
            $base = $running ? CarbonImmutable::instance($locked->current_period_end) : $now;

            $locked->forceFill([
                'status' => Subscription::ACTIVE,
                'cancel_at_period_end' => false,
                'current_period_start' => $running ? ($locked->current_period_start ?? $now) : $now,
                'current_period_end' => $base->addMonth(),
            ])->save();

            $this->recordPayment($locked, $paymentId, $totalMinor);

            return $locked->refresh();
        });
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
