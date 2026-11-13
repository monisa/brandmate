<?php

namespace App\Domain\Influencers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A creator's account on one platform, with its latest known metrics.
 * This is the unit that is searched, compared and shortlisted.
 */
class SocialProfile extends Model
{
    public const PLATFORMS = ['instagram', 'youtube', 'facebook'];

    protected $fillable = [
        'influencer_id', 'platform', 'username', 'profile_url', 'avatar_url', 'is_verified',
        'followers', 'following', 'engagement_rate', 'avg_likes', 'avg_comments', 'avg_views',
        'data_source', 'provider', 'sync_status', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'followers' => 'integer',
            'following' => 'integer',
            'engagement_rate' => 'float',
            'avg_likes' => 'integer',
            'avg_comments' => 'integer',
            'avg_views' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }
}
