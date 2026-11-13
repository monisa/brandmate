<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Subscriptions\Entitlements;
use App\Domain\Subscriptions\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lets the super admin look a person up, switch their sign-in off or on, and fix a subscription by
 * hand when a payment went wrong. One subscription covers a creator, or a whole brand company.
 */
class AccessController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $term = '%'.addcslashes($data['search'], '%_\\').'%';

        $users = User::with(['membership.company'])
            ->where('is_super_admin', false)
            ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json(['data' => $users->map(fn (User $user) => $this->row($user))->values()]);
    }

    public function setActive(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $user = $this->person($request, $id);

        $user->forceFill(['deactivated_at' => $data['active'] ? null : now()])->save();
        if (! $data['active']) {
            $user->tokens()->delete(); // signs them out everywhere
        }
        $audit->log($data['active'] ? 'admin.user_reactivated' : 'admin.user_deactivated', $user);

        return response()->json(['data' => $this->row($user->fresh(['membership.company']))]);
    }

    /** Adds or removes access by hand, for example when a payment went through but the unlock did not. */
    public function updateSubscription(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['grant', 'revoke'])],
            'days' => ['required_if:action,grant', 'integer', 'min:1', 'max:366'],
        ]);
        $user = $this->person($request, $id);

        if ($data['action'] === 'grant') {
            $this->subscriptions->grantManually($user, (int) $data['days']);
            $audit->log('subscription.granted', $user, ['days' => (int) $data['days'], 'by' => 'super admin']);
        } else {
            $this->subscriptions->revokeManually($user);
            $audit->log('subscription.revoked', $user, ['by' => 'super admin']);
        }

        return response()->json(['data' => $this->row($user->fresh(['membership.company']))]);
    }

    /** Never a super admin, and never the person making the change. */
    private function person(Request $request, int $id): User
    {
        $user = User::with('membership.company')->findOrFail($id);
        abort_if($user->isSuperAdmin() || $user->id === $request->user()->id, 422, 'That account cannot be changed here.');

        return $user;
    }

    /** @return array<string, mixed> */
    private function row(User $user): array
    {
        $subscription = Entitlements::subscriptionOf($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'kind' => $user->isCreator() ? 'creator' : 'brand',
            'company' => $user->membership?->company?->name,
            'deactivated' => $user->deactivated_at !== null,
            'subscription' => [
                'active' => $subscription?->grantsAccess() ?? false,
                'status' => $subscription?->status ?? 'none',
                'until' => $subscription?->current_period_end?->toIso8601String(),
            ],
        ];
    }
}
