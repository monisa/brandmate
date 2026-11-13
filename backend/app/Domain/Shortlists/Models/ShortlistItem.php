<?php

namespace App\Domain\Shortlists\Models;

use App\Domain\Influencers\Models\SocialProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An influencer saved in a shortlist. Has no company_id of its own: it is
 * only ever reached through its (tenant-scoped) shortlist.
 */
class ShortlistItem extends Model
{
    protected $table = 'shortlist_influencers';

    protected $fillable = ['shortlist_id', 'social_profile_id', 'notes', 'added_by'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SocialProfile::class, 'social_profile_id');
    }
}
