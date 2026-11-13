<?php

namespace App\Domain\Search;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The contract the API depends on. The MVP implementation queries MySQL;
 * an OpenSearch/Elasticsearch implementation can replace it later by
 * changing one binding in AppServiceProvider, with no API change.
 */
interface InfluencerSearch
{
    /** "newest" orders by when the creator joined; it defaults to newest first. */
    public const SORTS = ['followers', 'engagement_rate', 'avg_likes', 'avg_views', 'name', 'newest'];

    /**
     * @param  array{q?: ?string, platform?: ?string, category?: ?string, country?: ?string,
     *     followers_min?: ?int, followers_max?: ?int, engagement_min?: ?float, engagement_max?: ?float,
     *     verified?: ?bool, registered?: ?bool, sort?: ?string, direction?: ?string}  $filters
     */
    public function search(array $filters, int $perPage): LengthAwarePaginator;
}
