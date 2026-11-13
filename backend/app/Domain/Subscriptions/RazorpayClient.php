<?php

namespace App\Domain\Subscriptions;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The few Razorpay calls the app needs. Keys come from the environment; nothing is hard-coded.
 * https://razorpay.com/docs/api/payments/subscriptions/
 */
class RazorpayClient
{
    private const BASE = 'https://api.razorpay.com/v1';

    public function keyId(): ?string
    {
        return config('subscription.razorpay.key_id') ?: null;
    }

    /** True once the keys and the plan are set, so people can actually pay. */
    public function configured(): bool
    {
        return $this->keyId() && config('subscription.razorpay.key_secret') && config('subscription.razorpay.plan_id');
    }

    /** @return array<string, mixed> */
    public function createPlan(): array
    {
        return $this->http()->post('/plans', [
            'period' => 'monthly',
            'interval' => 1,
            'item' => [
                'name' => 'BrandMate monthly subscription',
                'description' => 'Rs 999 a month plus 18% GST',
                'amount' => SubscriptionPricing::totalMinor(),
                'currency' => config('subscription.currency'),
            ],
        ])->throw()->json();
    }

    /**
     * @param  array<string, string>  $notes  who this is for, so a webhook can be matched even if the local row is missing
     * @return array<string, mixed>
     */
    public function createSubscription(array $notes): array
    {
        return $this->http()->post('/subscriptions', [
            'plan_id' => config('subscription.razorpay.plan_id'),
            'total_count' => 120, // ten years of months; the person can cancel any time
            'quantity' => 1,
            'customer_notify' => 1,
            'notes' => $notes,
        ])->throw()->json();
    }

    /** Stops renewals; what was paid for stays valid until the period ends. */
    public function cancelSubscription(string $subscriptionId): void
    {
        $this->http()->post("/subscriptions/{$subscriptionId}/cancel", ['cancel_at_cycle_end' => 1])->throw();
    }

    /** The signature Checkout returns after a subscription payment: HMAC of "payment_id|subscription_id". */
    public function paymentSignatureIsValid(string $paymentId, string $subscriptionId, string $signature): bool
    {
        $secret = (string) config('subscription.razorpay.key_secret');
        if ($secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $paymentId.'|'.$subscriptionId, $secret), $signature);
    }

    /** A webhook is genuine only when its body matches the signature made with the webhook secret. */
    public function webhookSignatureIsValid(string $body, string $signature): bool
    {
        $secret = (string) config('subscription.razorpay.webhook_secret');
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE)
            ->withBasicAuth((string) $this->keyId(), (string) config('subscription.razorpay.key_secret'))
            ->acceptJson()
            ->timeout(20);
    }
}
