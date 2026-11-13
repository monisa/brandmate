<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Subscriptions\Entitlements;
use App\Domain\Subscriptions\RazorpayClient;
use App\Domain\Subscriptions\SubscriptionPricing;
use App\Domain\Subscriptions\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The signed-in creator's or brand company's own subscription. A creator manages their own; for a
 * brand, only someone who can manage billing (the Company Admin) pays, and the whole team benefits.
 */
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $service, private RazorpayClient $razorpay) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->summary($request->user())]);
    }

    /** Starts a Razorpay subscription. The browser then opens Checkout with the returned values. */
    public function checkout(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorizeManage($request->user());

        try {
            $checkout = $this->service->startCheckout($request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
        $audit->log('subscription.checkout_started', null);

        return response()->json(['data' => $checkout]);
    }

    /** Verifies the payment Checkout reported and switches access on. */
    public function confirm(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorizeManage($request->user());
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string', 'max:100'],
            'razorpay_subscription_id' => ['required', 'string', 'max:100'],
            'razorpay_signature' => ['required', 'string', 'max:200'],
        ]);

        $subscription = $this->service->confirmPayment(
            $request->user(), $data['razorpay_payment_id'], $data['razorpay_subscription_id'], $data['razorpay_signature'],
        );
        $audit->log('subscription.activated', $subscription);

        return response()->json(['data' => $this->summary($request->user())]);
    }

    public function cancel(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorizeManage($request->user());

        $subscription = $this->service->cancel($request->user());
        $audit->log('subscription.cancelled', $subscription);

        return response()->json(['data' => $this->summary($request->user())]);
    }

    private function authorizeManage(User $user): void
    {
        abort_unless($this->canManage($user), 403, 'Only the company admin can manage the subscription.');
    }

    private function canManage(User $user): bool
    {
        return $user->isCreator() || ($user->membership !== null && $user->hasPermission('billing.manage'));
    }

    /** @return array<string, mixed> */
    private function summary(User $user): array
    {
        $subscription = $this->service->subscriptionFor($user);

        return [
            'enforced' => Entitlements::enforced(),
            'active' => Entitlements::allows($user),
            'status' => $subscription?->status ?? 'none',
            'gateway' => $subscription?->gateway,
            'current_period_end' => $subscription?->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => (bool) $subscription?->cancel_at_period_end,
            'can_manage' => $this->canManage($user),
            'payments_ready' => (bool) $this->razorpay->configured(),
            'plan' => SubscriptionPricing::toApi(),
            'payments' => $subscription
                ? $subscription->payments()->latest('id')->limit(24)->get()->map->toApi()->values()
                : [],
        ];
    }
}
