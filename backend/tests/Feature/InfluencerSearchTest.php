<?php

namespace Tests\Feature;

use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use Database\Seeders\SampleInfluencerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InfluencerSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile('Asha Rao', 'Food', 'IN', 'instagram', 'asha.eats', 50_000, 4.5);
        $this->profile('Ben Lim', 'Technology', 'SG', 'youtube', 'benreviews', 900_000, 2.1, verified: true);
        $this->profile('Chitra Nair', 'Food', 'IN', 'youtube', 'chitra_cooks', 200_000, null);
        $this->profile('Dev Shah', 'Fitness', 'IN', 'facebook', 'devfit', 8_000, 7.8);

        Sanctum::actingAs($this->createMember($this->createCompany()));
    }

    private function profile(string $name, string $category, string $country, string $platform, string $username, int $followers, ?float $engagement, bool $verified = false): SocialProfile
    {
        $influencer = Influencer::create(['name' => $name, 'category' => $category, 'country' => $country, 'bio' => "About {$category}"]);

        return SocialProfile::create([
            'influencer_id' => $influencer->id, 'platform' => $platform, 'username' => $username,
            'followers' => $followers, 'engagement_rate' => $engagement, 'is_verified' => $verified,
            'data_source' => 'sample', 'provider' => 'Sample data',
        ]);
    }

    private function names(string $query = ''): array
    {
        return collect($this->getJson('/api/v1/influencers'.$query)->assertOk()->json('data'))->pluck('name')->all();
    }

    public function test_default_order_is_most_followers_first(): void
    {
        $this->assertSame(['Ben Lim', 'Chitra Nair', 'Asha Rao', 'Dev Shah'], $this->names());
    }

    public function test_keyword_matches_name_username_and_bio(): void
    {
        $this->assertSame(['Asha Rao'], $this->names('?q=asha'));
        $this->assertSame(['Ben Lim'], $this->names('?q=benreviews'));
        $this->assertSame(['Dev Shah'], $this->names('?q=fitness'));
    }

    public function test_filters_combine(): void
    {
        $this->assertSame(['Chitra Nair', 'Asha Rao'], $this->names('?category=Food'));
        $this->assertSame(['Chitra Nair'], $this->names('?category=Food&platform=youtube'));
        $this->assertSame(['Ben Lim'], $this->names('?country=sg'));
        $this->assertSame(['Ben Lim'], $this->names('?verified=1'));
    }

    public function test_range_filters(): void
    {
        $this->assertSame(['Chitra Nair', 'Asha Rao'], $this->names('?followers_min=10000&followers_max=500000'));
        // Unknown engagement never matches an engagement filter.
        $this->assertSame(['Asha Rao', 'Dev Shah'], $this->names('?engagement_min=4'));
    }

    public function test_sorting_puts_unknown_values_last_in_both_directions(): void
    {
        $this->assertSame(['Dev Shah', 'Asha Rao', 'Ben Lim', 'Chitra Nair'], $this->names('?sort=engagement_rate'));
        $this->assertSame(['Ben Lim', 'Asha Rao', 'Dev Shah', 'Chitra Nair'], $this->names('?sort=engagement_rate&direction=asc'));
        $this->assertSame(['Asha Rao', 'Ben Lim', 'Chitra Nair', 'Dev Shah'], $this->names('?sort=name'));
    }

    public function test_results_are_paginated(): void
    {
        $this->getJson('/api/v1/influencers?per_page=3&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_no_match_returns_an_empty_list(): void
    {
        $this->getJson('/api/v1/influencers?q=zzzz')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->getJson('/api/v1/influencers?platform=tiktok')->assertUnprocessable()->assertJsonValidationErrors('platform');
        $this->getJson('/api/v1/influencers?sort=password')->assertUnprocessable();
    }

    public function test_unknown_metrics_are_null_not_zero(): void
    {
        $id = SocialProfile::where('username', 'chitra_cooks')->value('id');

        $this->getJson("/api/v1/influencers/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Chitra Nair')
            ->assertJsonPath('data.engagement_rate', null)
            ->assertJsonPath('data.avg_views', null)
            ->assertJsonPath('data.data_source', 'sample');
    }

    public function test_profile_lists_the_creators_other_platforms(): void
    {
        $instagram = SocialProfile::where('username', 'asha.eats')->first();
        SocialProfile::create([
            'influencer_id' => $instagram->influencer_id, 'platform' => 'youtube', 'username' => 'ashaeats',
            'followers' => 12_000, 'data_source' => 'sample', 'provider' => 'Sample data',
        ]);

        $this->getJson("/api/v1/influencers/{$instagram->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.other_platforms')
            ->assertJsonPath('data.other_platforms.0.platform', 'youtube');
    }

    public function test_compare_returns_the_requested_influencers_in_order(): void
    {
        $ids = SocialProfile::orderBy('id')->pluck('id');

        $this->getJson("/api/v1/influencers/compare?ids[]={$ids[2]}&ids[]={$ids[0]}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Chitra Nair')
            ->assertJsonPath('data.1.name', 'Asha Rao');
    }

    public function test_compare_is_limited_to_five(): void
    {
        $this->getJson('/api/v1/influencers/compare?'.http_build_query(['ids' => [1, 2, 3, 4, 5, 6]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
    }

    public function test_filter_options_come_from_the_data(): void
    {
        $this->getJson('/api/v1/influencers/filters')
            ->assertOk()
            ->assertJsonPath('data.categories', ['Fitness', 'Food', 'Technology'])
            ->assertJsonPath('data.countries', ['IN', 'SG']);
    }

    public function test_sample_seeder_is_repeatable_and_marks_its_rows(): void
    {
        SocialProfile::query()->delete();
        Influencer::query()->delete();

        $this->seed(SampleInfluencerSeeder::class);
        $count = SocialProfile::count();
        $this->seed(SampleInfluencerSeeder::class);

        $this->assertGreaterThan(100, $count);
        $this->assertSame($count, SocialProfile::count());
        $this->assertSame(0, SocialProfile::where('data_source', '!=', 'sample')->count());
        $this->assertSame(0, SocialProfile::where('profile_url', 'not like', 'https://example.com/%')->count());
    }
}
