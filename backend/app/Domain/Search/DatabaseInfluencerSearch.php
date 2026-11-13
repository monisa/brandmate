<?php

namespace App\Domain\Search;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DatabaseInfluencerSearch implements InfluencerSearch
{
    public function search(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = SocialProfile::query()
            ->select('social_profiles.*')
            ->join('influencers', 'influencers.id', '=', 'social_profiles.influencer_id')
            // Creators choose when they are visible, and the platform team must have approved them.
            ->where('influencers.status', Influencer::LISTED)
            ->where('influencers.approval_status', ApprovalStatus::APPROVED)
            ->with('influencer.user');

        if (filled($filters['q'] ?? null)) {
            // Escape LIKE wildcards so "100%" searches for the literal text.
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';

            // A channel name that is hidden from this viewer must not be findable by typing it either.
            $matchUsername = $filters['match_username'] ?? true;

            $query->where(fn ($w) => $w
                ->where('influencers.name', 'like', $term)
                ->when($matchUsername, fn ($inner) => $inner->orWhere('social_profiles.username', 'like', $term))
                ->orWhere('influencers.bio', 'like', $term));
        }

        $query
            ->when($filters['platform'] ?? null, fn ($q, $v) => $q->where('social_profiles.platform', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('influencers.category', $v))
            ->when($filters['country'] ?? null, fn ($q, $v) => $q->where('influencers.country', strtoupper($v)));

        // Range filters only match rows where the metric is known.
        foreach ([
            'followers_min' => ['social_profiles.followers', '>='],
            'followers_max' => ['social_profiles.followers', '<='],
            'engagement_min' => ['social_profiles.engagement_rate', '>='],
            'engagement_max' => ['social_profiles.engagement_rate', '<='],
        ] as $key => [$column, $operator]) {
            if (($filters[$key] ?? null) !== null) {
                $query->where($column, $operator, $filters[$key]);
            }
        }

        if (($filters['verified'] ?? null) !== null) {
            $query->where('social_profiles.is_verified', (bool) $filters['verified']);
        }

        // Only creators who signed up themselves, not imported or sample records.
        if (filter_var($filters['registered'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNotNull('influencers.user_id');
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'followers';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';
        $column = match ($sort) {
            'name' => 'influencers.name',
            'newest' => 'influencers.created_at',
            default => 'social_profiles.'.$sort,
        };

        if ($sort === 'name' && ! isset($filters['direction'])) {
            $direction = 'asc';
        }

        return $query
            ->orderByRaw($column.' IS NULL')   // unknown values always last
            ->orderBy($column, $direction)
            ->orderBy('social_profiles.id')
            ->paginate($perPage);
    }
}
