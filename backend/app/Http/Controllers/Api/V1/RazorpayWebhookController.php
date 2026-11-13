<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Subscriptions\RazorpayClient;
use App\Domain\Subscriptions\SubscriptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Razorpay tells us about renewals, failures and cancellations here. Only a request signed with
 * the webhook secret is believed, and each event is applied once.
 */
class RazorpayWebhookController extends Controller
{
    public function handle(Request $request, RazorpayClient $razorpay, SubscriptionService $service): JsonResponse
    {
        $body = $request->getContent();

        if (! $razorpay->webhookSignatureIsValid($body, (string) $request->header('X-Razorpay-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid body.'], 400);
        }

        $eventId = (string) ($request->header('X-Razorpay-Event-Id') ?: hash('sha256', $body));
        $service->handleWebhook($eventId, $payload);

        return response()->json(['ok' => true]);
    }
}
