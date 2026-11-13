<?php

namespace App\Domain\Subscriptions;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The few Razorpay calls the app needs. Keys come from the environment; nothing is hard-coded.
 * https://razorpay.com/docs/api/orders/
 */
class RazorpayClient
{
    private const BASE = 'https://api.razorpay.com/v1';

    public function keyId(): ?string
    {
        return config('subscription.razorpay.key_id') ?: null;
    }

    /** True once the keys are set, so people can actually pay. */
    public function configured(): bool
    {
        return $this->keyId() && config('subscription.razorpay.key_secret');
    }

    /**
     * A one-time order for one month. Razorpay's auto-renewing subscriptions are not available on
     * accounts where customers bear the fees, so each month is paid for by hand.
     *
     * @param  array<string, string>  $notes  who this is for, so a webhook can be matched even if the local row is missing
     * @return array<string, mixed>
     */
    public function createOrder(int $amountMinor, string $receipt, array $notes): array
    {
        return $this->http()->post('/orders', [
            'amount' => $amountMinor,
            'currency' => config('subscription.currency'),
            'receipt' => substr($receipt, 0, 40),
            'notes' => $notes,
        ])->throw()->json();
    }

    /** The signature Checkout returns after an order is paid: HMAC of "order_id|payment_id". */
    public function paymentSignatureIsValid(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = (string) config('subscription.razorpay.key_secret');
        if ($secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $orderId.'|'.$paymentId, $secret), $signature);
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
