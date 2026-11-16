<?php

namespace App\Console\Commands;

use App\Domain\Influencers\Models\SocialProfile;
use App\Domain\Influencers\ProfileStats;
use Illuminate\Console\Command;

/**
 * Re-reads followers, engagement and averages from the platforms for creators who signed up
 * themselves (the sample influencers are left alone). Runs daily from the scheduler; run it by hand
 * after adding an API key to fill in everyone straight away.
 *
 *   php artisan profiles:refresh            only those not refreshed in the last 7 days
 *   php artisan profiles:refresh --all      everyone
 */
class RefreshProfileStats extends Command
{
    protected $signature = 'profiles:refresh {--all : Refresh every account, not just stale ones} {--days=7 : How old the numbers must be to refresh them}';

    protected $description = 'Refresh creators\' followers, engagement rate and averages from the platforms\' own APIs';

    public function handle(): int
    {
        $done = $failed = 0;

        SocialProfile::query()
            ->whereHas('influencer', fn ($q) => $q->whereNotNull('user_id'))
            ->whereIn('platform', ['youtube', 'instagram'])
            ->when(! $this->option('all'), fn ($q) => $q->where(fn ($w) => $w
                ->where('data_source', '!=', 'official_api')
                ->orWhereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', now()->subDays(max(1, (int) $this->option('days'))))))
            ->orderBy('id')
            ->limit(200) // stays inside the API quotas; the rest follow on the next run
            ->get()
            ->each(function (SocialProfile $profile) use (&$done, &$failed) {
                ProfileStats::refresh($profile) ? $done++ : $failed++;
            });

        $this->info("Updated {$done}, unchanged or unavailable {$failed}");

        return self::SUCCESS;
    }
}
