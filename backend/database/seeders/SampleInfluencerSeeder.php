<?php

namespace Database\Seeders;

use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Fictional influencers for development and demos.
 *
 * Nothing here describes a real person or account. Every row is marked
 * data_source = "sample", and profile links point at example.com so sample
 * records can never be mistaken for, or link to, real social accounts.
 *
 * Remove them before going live:
 *   Influencer::whereHas('profiles', fn ($q) => $q->where('data_source', 'sample'))->delete();
 */
class SampleInfluencerSeeder extends Seeder
{
    private const FIRST = ['Aarav', 'Diya', 'Kabir', 'Meera', 'Rohan', 'Ananya', 'Vihaan', 'Isha', 'Arjun', 'Tara',
        'Nikhil', 'Sana', 'Dev', 'Riya', 'Kiran', 'Leela', 'Omar', 'Zoya', 'Neil', 'Maya',
        'Aditi', 'Farhan', 'Gauri', 'Hari', 'Jaya', 'Lucas', 'Emma', 'Noah', 'Chloe', 'Ethan'];

    private const LAST = ['Varma', 'Nair', 'Kapoor', 'Menon', 'Shah', 'Iyer', 'Reddy', 'Bose', 'Pillai', 'Chopra',
        'Das', 'Khan', 'Mathew', 'Rao', 'Sethi', 'Thomas', 'Gill', 'Joshi', 'Carter', 'Lim'];

    /** category => bio fragments */
    private const CATEGORIES = [
        'Fashion' => 'Everyday outfits, thrift finds and honest brand reviews',
        'Beauty' => 'Skincare routines and makeup that works in humid weather',
        'Fitness' => 'Home workouts, running plans and realistic nutrition',
        'Food' => 'Street food trails and thirty-minute home recipes',
        'Travel' => 'Budget itineraries, train journeys and hidden stays',
        'Technology' => 'Phone and laptop reviews without the jargon',
        'Gaming' => 'Live streams, walkthroughs and esports commentary',
        'Parenting' => 'Life with two kids, school lunches and weekend crafts',
        'Finance' => 'Personal finance basics, saving and first investments',
        'Education' => 'Exam preparation tips and study-with-me sessions',
        'Comedy' => 'Sketches about office life and family group chats',
        'Music' => 'Covers, original songs and behind-the-scenes sessions',
    ];

    /** [country, state, city, language] */
    private const PLACES = [
        ['IN', 'Maharashtra', 'Mumbai', 'Hindi'], ['IN', 'Karnataka', 'Bengaluru', 'English'],
        ['IN', 'Delhi', 'New Delhi', 'Hindi'], ['IN', 'Kerala', 'Kochi', 'Malayalam'],
        ['IN', 'Tamil Nadu', 'Chennai', 'Tamil'], ['IN', 'Telangana', 'Hyderabad', 'Telugu'],
        ['IN', 'Maharashtra', 'Pune', 'Marathi'], ['IN', 'West Bengal', 'Kolkata', 'Bengali'],
        ['IN', 'Kerala', 'Thiruvananthapuram', 'Malayalam'], ['IN', 'Gujarat', 'Ahmedabad', 'Gujarati'],
        ['AE', 'Dubai', 'Dubai', 'English'], ['GB', 'England', 'London', 'English'],
        ['US', 'New York', 'New York', 'English'], ['SG', 'Singapore', 'Singapore', 'English'],
    ];

    /** First names above that get a woman's placeholder portrait; the rest get a man's. */
    private const WOMEN = ['Diya', 'Meera', 'Ananya', 'Isha', 'Tara', 'Sana', 'Riya', 'Leela', 'Zoya', 'Maya',
        'Aditi', 'Gauri', 'Jaya', 'Emma', 'Chloe'];

    /**
     * Placeholder portrait for a fictional creator, so the social-style cards
     * have a photo. randomuser.me serves stock portraits meant for exactly this.
     */
    private function avatarFor(string $name, int $index): string
    {
        $first = Str::before($name, ' ');
        $folder = in_array($first, self::WOMEN, true) ? 'women' : 'men';

        return "https://randomuser.me/api/portraits/{$folder}/".($index % 100).'.jpg';
    }

    public function run(): void
    {
        if (SocialProfile::where('data_source', 'sample')->exists()) {
            $this->backfillAvatars(); // seeded before photos existed
            return;
        }

        mt_srand(20261002); // same data on every machine

        $categories = array_keys(self::CATEGORIES);
        $usedNames = [];

        for ($i = 0; $i < 72; $i++) {
            do {
                $name = self::FIRST[mt_rand(0, count(self::FIRST) - 1)].' '.self::LAST[mt_rand(0, count(self::LAST) - 1)];
            } while (isset($usedNames[$name]));
            $usedNames[$name] = true;

            $category = $categories[$i % count($categories)];
            [$country, $state, $city, $language] = self::PLACES[mt_rand(0, count(self::PLACES) - 1)];

            $influencer = Influencer::create([
                'name' => $name,
                'category' => $category,
                'bio' => self::CATEGORIES[$category].'. Based in '.$city.'.',
                'country' => $country,
                'state' => $state,
                'city' => $city,
                'language' => $language,
            ]);

            // Audience size tier: mostly small and mid-size creators, a few large ones.
            $tier = [5_000, 20_000, 60_000, 150_000, 400_000, 1_200_000, 3_500_000][min(6, intdiv(mt_rand(0, 99), 15))];

            $platforms = SocialProfile::PLATFORMS;
            shuffle($platforms); // uses the seeded generator
            $count = [1, 2, 2, 3][mt_rand(0, 3)];

            $avatar = $this->avatarFor($name, $i);
            foreach (array_slice($platforms, 0, $count) as $platform) {
                $this->createProfile($influencer, $platform, $tier, $avatar);
            }
        }
    }

    /** Gives sample creators from an earlier seed a photo. Leaves every other record alone. */
    private function backfillAvatars(): void
    {
        Influencer::whereHas('profiles', fn ($q) => $q->where('data_source', 'sample'))
            ->orderBy('id')
            ->get()
            ->each(function (Influencer $influencer, int $index) {
                SocialProfile::where('influencer_id', $influencer->id)
                    ->where('data_source', 'sample')
                    ->whereNull('avatar_url')
                    ->update(['avatar_url' => $this->avatarFor($influencer->name, $index)]);
            });
    }

    private function createProfile(Influencer $influencer, string $platform, int $tier, string $avatar): void
    {
        $followers = (int) round($tier * (mt_rand(60, 180) / 100));

        // Smaller audiences tend to engage more.
        $engagement = round(max(0.4, 9.5 - log10($followers) * 1.35 + mt_rand(-80, 120) / 100), 2);
        $likes = (int) round($followers * $engagement / 100 * 0.92);
        $comments = (int) round($followers * $engagement / 100 * 0.08);

        $handle = Str::of($influencer->name)->lower()->replace(' ', mt_rand(0, 1) ? '.' : '_')
            .['', '', '.official', '_'.Str::lower($influencer->category)][mt_rand(0, 3)];

        // Leave realistic gaps so the UI's "Data unavailable" paths are exercised.
        $unknownEngagement = mt_rand(1, 100) <= 12;

        SocialProfile::create([
            'influencer_id' => $influencer->id,
            'platform' => $platform,
            'username' => $handle,
            'profile_url' => "https://example.com/sample/{$platform}/{$handle}",
            'avatar_url' => $avatar,
            'is_verified' => $platform === 'facebook' && mt_rand(1, 100) <= 30 ? null : $followers > 300_000 && mt_rand(1, 100) <= 70,
            'followers' => $followers,
            // YouTube has no "following" count.
            'following' => $platform === 'youtube' ? null : mt_rand(80, 2400),
            'engagement_rate' => $unknownEngagement ? null : $engagement,
            'avg_likes' => $unknownEngagement ? null : $likes,
            'avg_comments' => $unknownEngagement ? null : $comments,
            'avg_views' => match ($platform) {
                'youtube' => (int) round($followers * mt_rand(15, 70) / 100),
                'instagram' => mt_rand(1, 100) <= 75 ? (int) round($followers * mt_rand(20, 120) / 100) : null,
                default => null,
            },
            'data_source' => 'sample',
            'provider' => 'Sample data',
            'sync_status' => 'synced',
            'last_synced_at' => now()->subDays(mt_rand(0, 20)),
        ]);
    }
}
