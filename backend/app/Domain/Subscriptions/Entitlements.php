<?php

namespace App\Domain\Subscriptions;

use App\Domain\Influencers\Models\Influencer;
use App\Domain\Subscriptions\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who may see phone numbers, e-mail addresses and channel names, open full profiles and chat.
 * A creator subscribes for themselves; a brand company subscribes once for its whole team.
 * While `subscription.enforced` is off everyone has access, so enabling the feature never
 * locks people out before payments are connected.
 */
class Entitlements
{
    public const MASK_KEY = 'subscription.mask';

    public static function mask(): string
    {
        return (string) config(self::MASK_KEY);
    }

    public static function enforced(): bool
    {
        return (bool) config('subscription.enforced');
    }

    /** Whether this person can see unmasked contact details. Super admins always can. */
    public static function allows(?User $user): bool
    {
        if (! self::enforced()) {
            return true;
        }
        if ($user === null) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        return self::subscriptionOf($user)?->grantsAccess() ?? false;
    }

    /** The same check, worked out once per request however many cards are in the response. */
    public static function allowsRequest(Request $request): bool
    {
        return $request->attributes->get('entitled') ?? tap(
            self::allows($request->user()),
            fn (bool $allowed) => $request->attributes->set('entitled', $allowed),
        );
    }

    /** The subscription that covers this person: their own for a creator, their company's for a brand. */
    public static function subscriptionOf(User $user): ?Subscription
    {
        if ($user->isCreator()) {
            return Subscription::where('user_id', $user->id)->first();
        }

        $companyId = $user->membership?->company_id;

        return $companyId ? Subscription::where('company_id', $companyId)->first() : null;
    }

    /** Whether a brand company is subscribed (for the other side of a chat). */
    public static function companyAllows(int $companyId): bool
    {
        if (! self::enforced()) {
            return true;
        }

        return Subscription::where('company_id', $companyId)->first()?->grantsAccess() ?? false;
    }

    /** Whether a creator is subscribed (for the other side of a chat). */
    public static function creatorAllows(Influencer $creator): bool
    {
        if (! self::enforced()) {
            return true;
        }

        return $creator->user_id !== null && (Subscription::where('user_id', $creator->user_id)->first()?->grantsAccess() ?? false);
    }

    /** Masks a value when the viewer is locked out. */
    public static function reveal(?string $value, bool $unlocked): ?string
    {
        return $unlocked ? $value : self::mask();
    }
}
