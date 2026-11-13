<?php

namespace App\Domain\Influencers\Models;

use App\Domain\Companies\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A brand opening a creator's profile. Shown to the creator as counts and the visiting company. */
class ProfileView extends Model
{
    public $timestamps = false;

    protected $fillable = ['influencer_id', 'company_id', 'user_id', 'viewed_at'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Records a view, unless the same person already viewed this creator within the last hour
     * (reloading or going back and forth is not a new visit). Only creators who signed up
     * themselves are tracked: sample and imported profiles have nobody to show it to.
     */
    public static function record(Influencer $creator, int $companyId, ?int $userId): void
    {
        if ($creator->user_id === null) {
            return;
        }

        $recent = static::where('influencer_id', $creator->id)
            ->where('user_id', $userId)
            ->where('viewed_at', '>=', now()->subHour())
            ->exists();

        if (! $recent) {
            static::create([
                'influencer_id' => $creator->id,
                'company_id' => $companyId,
                'user_id' => $userId,
                'viewed_at' => now(),
            ]);
        }
    }
}
