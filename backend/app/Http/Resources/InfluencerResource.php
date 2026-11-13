<?php

namespace App\Http\Resources;

use App\Domain\Subscriptions\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An influencer as the app shows it: one platform account plus the creator's
 * details. A null metric means the data is unavailable.
 *
 * @mixin \App\Domain\Influencers\Models\SocialProfile
 */
class InfluencerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $creator = $this->influencer;
        // Phone numbers, e-mail addresses and channel names are shown only to subscribers.
        $unlocked = Entitlements::allowsRequest($request);

        return [
            'id' => $this->id,
            'creator_id' => $this->influencer_id,
            'name' => $creator->name,
            'locked' => ! $unlocked,
            'username' => Entitlements::reveal($this->username, $unlocked),
            'platform' => $this->platform,
            'profile_url' => $unlocked ? $this->profile_url : null,
            'contact' => [
                'email' => Entitlements::reveal($creator->user?->email, $unlocked),
                'phone' => Entitlements::reveal($creator->user?->phone, $unlocked),
            ],
            'avatar_url' => $this->avatar_url,
            'bio' => $creator->bio,
            'category' => $creator->category,
            'country' => $creator->country,
            'state' => $creator->state,
            'city' => $creator->city,
            'language' => $creator->language,
            'is_verified' => $this->is_verified,
            'followers' => $this->followers,
            'following' => $this->following,
            'engagement_rate' => $this->engagement_rate,
            'avg_likes' => $this->avg_likes,
            'avg_comments' => $this->avg_comments,
            'avg_views' => $this->avg_views,
            'is_registered_creator' => $creator->user_id !== null,
            'data_source' => $this->data_source,
            'provider' => $this->provider,
            'sync_status' => $this->sync_status,
            'last_updated_at' => $this->last_synced_at?->toIso8601String(),
            'packages' => $this->when($creator->relationLoaded('packages'), fn () => $creator->packages
                ->where('is_active', true)
                ->map(fn ($package) => $package->toApi())
                ->values()),
            'other_platforms' => $this->when($creator->relationLoaded('profiles'), fn () => $creator->profiles
                ->where('id', '!=', $this->id)
                ->map(fn ($p) => ['id' => $p->id, 'platform' => $p->platform, 'username' => Entitlements::reveal($p->username, $unlocked), 'followers' => $p->followers])
                ->values()),
        ];
    }
}
