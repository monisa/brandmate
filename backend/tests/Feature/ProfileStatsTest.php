<?php

namespace Tests\Feature;

use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use App\Domain\Influencers\ProfileStats;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileStatsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $platform, string $username, string $url, bool $real = true): SocialProfile
    {
        $creator = new Influencer(['name' => 'Madhan', 'status' => Influencer::HIDDEN]);
        if ($real) {
            $creator->user()->associate(User::factory()->create(['account_type' => 'creator']));
        }
        $creator->save();

        return $creator->profiles()->create([
            'platform' => $platform, 'username' => $username, 'profile_url' => $url, 'followers' => 1000,
            'data_source' => 'self_reported', 'provider' => 'Entered by the creator', 'sync_status' => 'unverified', 'last_synced_at' => now(),
        ]);
    }

    public function test_youtube_numbers_come_from_the_channel_link(): void
    {
        config(['services.youtube.key' => 'yt-key']);
        Http::fake([
            'googleapis.com/youtube/v3/channels*' => Http::response(['items' => [[
                'statistics' => ['subscriberCount' => '2800000'],
                'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU123']],
            ]]]),
            'googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => [
                ['contentDetails' => ['videoId' => 'a']], ['contentDetails' => ['videoId' => 'b']],
            ]]),
            'googleapis.com/youtube/v3/videos*' => Http::response(['items' => [
                ['statistics' => ['viewCount' => '1000', 'likeCount' => '90', 'commentCount' => '10']],
                ['statistics' => ['viewCount' => '3000', 'likeCount' => '200', 'commentCount' => '100']],
            ]]),
        ]);
        $profile = $this->account('youtube', 'madhan', 'https://www.youtube.com/@madhangowri');

        $this->assertTrue(ProfileStats::refresh($profile));

        $profile->refresh();
        $this->assertSame(2800000, $profile->followers);
        $this->assertSame(2000, $profile->avg_views);
        $this->assertSame(145, $profile->avg_likes);
        $this->assertSame(55, $profile->avg_comments);
        $this->assertSame(10.0, $profile->engagement_rate); // (100/1000 = 10%, 300/3000 = 10%)
        $this->assertNull($profile->following);
        $this->assertSame('official_api', $profile->data_source);
        $this->assertSame('YouTube Data API', $profile->provider);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'channels') && $r['forHandle'] === '@madhangowri');
    }

    public function test_instagram_numbers_come_from_business_discovery(): void
    {
        config(['services.instagram.user_id' => '17841', 'services.instagram.token' => 'ig-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['business_discovery' => [
            'followers_count' => 10000, 'follows_count' => 321,
            'media' => ['data' => [['like_count' => 400, 'comments_count' => 20], ['like_count' => 600, 'comments_count' => 80]]],
        ]])]);
        $profile = $this->account('instagram', 'madhan.g', 'https://www.instagram.com/madhan.g/');

        $this->assertTrue(ProfileStats::refresh($profile));

        $profile->refresh();
        $this->assertSame(10000, $profile->followers);
        $this->assertSame(321, $profile->following);
        $this->assertSame(500, $profile->avg_likes);
        $this->assertSame(50, $profile->avg_comments);
        $this->assertSame(5.5, $profile->engagement_rate); // 550 / 10000
        $this->assertNull($profile->avg_views);
        $this->assertSame('Instagram Graph API', $profile->provider);
    }

    public function test_nothing_changes_without_keys_or_when_the_platform_refuses(): void
    {
        Http::fake(['googleapis.com/*' => Http::response(['error' => 'quota'], 403)]);
        $youtube = $this->account('youtube', 'a', 'https://www.youtube.com/@a');
        $this->assertFalse(ProfileStats::refresh($youtube)); // no key
        Http::assertNothingSent();

        config(['services.youtube.key' => 'yt-key']);
        $this->assertFalse(ProfileStats::refresh($youtube));
        $this->assertSame('self_reported', $youtube->refresh()->data_source);
        $this->assertSame(1000, $youtube->followers);

        $facebook = $this->account('facebook', 'b', 'https://www.facebook.com/b');
        $this->assertFalse(ProfileStats::refresh($facebook));
    }

    public function test_adding_an_account_reads_its_numbers_and_the_command_skips_sample_creators(): void
    {
        config(['services.youtube.key' => 'yt-key']);
        Http::fake([
            'googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['statistics' => ['subscriberCount' => '5000'], 'contentDetails' => []]]]),
        ]);

        $user = User::factory()->create(['account_type' => 'creator']);
        $profile = new Influencer(['name' => 'Riya', 'status' => Influencer::HIDDEN]);
        $profile->user()->associate($user);
        $profile->save();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/creator/accounts', [
            'platform' => 'youtube', 'username' => 'riya', 'profile_url' => 'https://www.youtube.com/@riya', 'followers' => 100,
        ])->assertCreated();
        $this->assertSame(5000, SocialProfile::where('username', 'riya')->value('followers'));

        $sample = $this->account('youtube', 'sample', 'https://www.youtube.com/@sample', real: false);
        $this->artisan('profiles:refresh', ['--all' => true])->assertSuccessful();
        $this->assertSame(1000, $sample->refresh()->followers); // sample records are left alone
    }
}
