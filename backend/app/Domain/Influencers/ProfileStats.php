<?php

namespace App\Domain\Influencers;

use App\Domain\Influencers\Models\SocialProfile;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads a creator's numbers from the platform's own public API, starting from the profile link they
 * entered: followers, following, average likes / comments / views and the engagement rate.
 *
 *  - YouTube: the YouTube Data API (YOUTUBE_API_KEY). Needs only the channel link. YouTube does not
 *    publish "following", so that stays empty.
 *  - Instagram: Meta's Business Discovery (IG_BUSINESS_ID + IG_ACCESS_TOKEN). Works for professional
 *    (business or creator) accounts only, and needs the Meta app approved for it.
 *  - Facebook: not available without Meta's Page Public Metadata approval, so nothing is fetched.
 *
 * Nothing is scraped. Without the keys, or when a platform refuses, the numbers the creator typed stay.
 */
class ProfileStats
{
    private const RECENT_POSTS = 12;

    public static function available(string $platform): bool
    {
        return match ($platform) {
            'youtube' => (bool) config('services.youtube.key'),
            'instagram' => (bool) (config('services.instagram.user_id') && config('services.instagram.token')),
            default => false,
        };
    }

    /**
     * Fetches the numbers and saves them with their source. Returns whether anything was updated.
     */
    public static function refresh(SocialProfile $profile): bool
    {
        if (! self::available($profile->platform)) {
            return false;
        }

        try {
            $stats = $profile->platform === 'youtube' ? self::youtube($profile) : self::instagram($profile);
        } catch (Throwable $e) {
            report($e);
            $stats = null;
        }

        if ($stats === null) {
            return false;
        }

        $profile->forceFill(array_filter($stats, fn ($v, $k) => $k !== 'provider' && $v !== null, ARRAY_FILTER_USE_BOTH) + [
            'data_source' => 'official_api',
            'provider' => $stats['provider'],
            'sync_status' => 'synced',
            'last_synced_at' => now(),
        ])->save();

        return true;
    }

    /** @return array<string, mixed>|null */
    private static function youtube(SocialProfile $profile): ?array
    {
        $key = (string) config('services.youtube.key');
        $api = 'https://www.googleapis.com/youtube/v3';

        $channel = Http::timeout(10)->get($api.'/channels', ['part' => 'statistics,contentDetails', 'key' => $key] + self::youtubeChannel($profile))
            ->throw()->json('items.0');
        if (! $channel) {
            return null;
        }

        $stats = [
            'followers' => ($channel['statistics']['hiddenSubscriberCount'] ?? false) ? null : (int) ($channel['statistics']['subscriberCount'] ?? 0),
            'following' => null,
            'provider' => 'YouTube Data API',
        ];

        $uploads = $channel['contentDetails']['relatedPlaylists']['uploads'] ?? null;
        if ($uploads) {
            $ids = collect(Http::timeout(10)->get($api.'/playlistItems', ['part' => 'contentDetails', 'playlistId' => $uploads, 'maxResults' => self::RECENT_POSTS, 'key' => $key])
                ->throw()->json('items', []))->pluck('contentDetails.videoId')->filter()->implode(',');

            if ($ids !== '') {
                $videos = Http::timeout(10)->get($api.'/videos', ['part' => 'statistics', 'id' => $ids, 'key' => $key])->throw()->json('items', []);
                $posts = collect($videos)->map(fn ($v) => [
                    'views' => (int) ($v['statistics']['viewCount'] ?? 0),
                    'likes' => (int) ($v['statistics']['likeCount'] ?? 0),
                    'comments' => (int) ($v['statistics']['commentCount'] ?? 0),
                ]);

                $stats += self::averages($posts->all());
                // Engagement on YouTube is measured against views: (likes + comments) / views, averaged over recent videos.
                $rates = $posts->filter(fn ($p) => $p['views'] > 0)->map(fn ($p) => ($p['likes'] + $p['comments']) / $p['views'] * 100);
                $stats['engagement_rate'] = $rates->isEmpty() ? null : self::percent($rates->avg());
            }
        }

        return $stats;
    }

    /** @return array<string, string> the query that finds this channel from the link the creator gave */
    private static function youtubeChannel(SocialProfile $profile): array
    {
        $path = trim((string) parse_url((string) $profile->profile_url, PHP_URL_PATH), '/');

        if (preg_match('#^channel/(UC[\w-]{20,})#', $path, $m)) {
            return ['id' => $m[1]];
        }
        if (preg_match('#^user/([\w.-]+)#', $path, $m)) {
            return ['forUsername' => $m[1]];
        }
        if (preg_match('#^@([\w.-]+)#', $path, $m)) {
            return ['forHandle' => '@'.$m[1]];
        }

        return ['forHandle' => '@'.$profile->username];
    }

    /** @return array<string, mixed>|null */
    private static function instagram(SocialProfile $profile): ?array
    {
        // The username was validated to letters, numbers, dots, dashes and underscores, so it is safe inside the field list.
        $fields = 'business_discovery.username('.$profile->username.'){followers_count,follows_count,media.limit('.self::RECENT_POSTS.'){like_count,comments_count}}';

        $found = Http::timeout(10)->get('https://graph.facebook.com/v21.0/'.config('services.instagram.user_id'), [
            'fields' => $fields,
            'access_token' => config('services.instagram.token'),
        ])->throw()->json('business_discovery');

        if (! $found) {
            return null;
        }

        $followers = (int) ($found['followers_count'] ?? 0);
        $posts = collect($found['media']['data'] ?? [])->map(fn ($m) => [
            'likes' => (int) ($m['like_count'] ?? 0),
            'comments' => (int) ($m['comments_count'] ?? 0),
        ]);

        $stats = ['followers' => $followers, 'following' => (int) ($found['follows_count'] ?? 0), 'provider' => 'Instagram Graph API'];

        if ($posts->isNotEmpty()) {
            $stats += self::averages($posts->all());
            // Instagram engagement is measured against followers: (likes + comments) / followers.
            $stats['engagement_rate'] = $followers > 0
                ? self::percent($posts->avg(fn ($p) => $p['likes'] + $p['comments']) / $followers * 100)
                : null;
        }

        return $stats;
    }

    /**
     * @param  list<array{views?: int, likes?: int, comments?: int}>  $posts
     * @return array<string, int|null>
     */
    private static function averages(array $posts): array
    {
        $posts = collect($posts);

        return [
            'avg_likes' => $posts->isEmpty() ? null : (int) round($posts->avg('likes')),
            'avg_comments' => $posts->isEmpty() ? null : (int) round($posts->avg('comments')),
            'avg_views' => $posts->contains(fn ($p) => isset($p['views'])) ? (int) round($posts->avg('views')) : null,
        ];
    }

    /** The column holds up to 999.99. */
    private static function percent(float $value): float
    {
        return round(min(999.99, max(0, $value)), 2);
    }
}
