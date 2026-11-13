<?php

namespace App\Domain\Search;

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
            ->where('influencers.status', Influencer::LISTED) // creators choose when they are visible
            ->with('influencer');

        if (filled($filters['q'] ?? null)) {
            // Escape LIKE wildcards so "100%" searches for the literal text.
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';

            $query->where(fn ($w) => $w
                ->where('influencers.name', 'like', $term)
                ->orWhere('social_profiles.username', 'like', $term)
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

        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'followers';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';
        $column = $sort === 'name' ? 'influencers.name' : 'social_profiles.'.$sort;

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
