<?php

namespace App\Domain\Influencers\Models;

use App\Domain\Creators\Models\CreatorPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Influencer extends Model
{
    public const LISTED = 'listed';

    public const HIDDEN = 'hidden';

    // user_id is set in code only, never from request input.
    protected $fillable = ['name', 'category', 'bio', 'country', 'state', 'city', 'language', 'status'];

    /** The creator's own login, when they registered themselves. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(CreatorPackage::class);
    }

    public function isListed(): bool
    {
        return $this->status === self::LISTED;
    }

    /** What still has to be filled in before brands can find this creator. */
    public function missingForListing(): array
    {
        $missing = [];

        if (! $this->category) {
            $missing[] = 'Choose a category.';
        }
        if (! $this->country) {
            $missing[] = 'Add your country.';
        }
        if (! $this->profiles()->exists()) {
            $missing[] = 'Add at least one social account.';
        }

        return $missing;
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(SocialProfile::class);
    }
}
