<?php

namespace Tests\Feature;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Creators\Models\CreatorPackage;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\ProfileView;
use App\Domain\Influencers\Models\SocialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreatorDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** A creator who signed up. By default a complete, listed profile with one account. */
    private function creator(string $name = 'Riya Sen', bool $complete = true): Influencer
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['account_type' => 'creator'])->save();

        $creator = new Influencer($complete
            ? ['name' => $name, 'category' => 'Food', 'country' => 'IN', 'bio' => 'Recipes', 'status' => Influencer::LISTED]
            : ['name' => $name, 'status' => Influencer::HIDDEN]);
        $creator->user()->associate($user);
        $creator->save();

        if ($complete) {
            SocialProfile::create([
                'influencer_id' => $creator->id, 'platform' => 'instagram', 'username' => strtolower(str_replace(' ', '.', $name)),
                'followers' => 1000, 'data_source' => 'self_reported', 'provider' => 'Self-reported',
            ]);
        }

        return $creator;
    }

    private function profileId(Influencer $creator): int
    {
        return $creator->profiles()->value('id');
    }

    private function types(array $actions): array
    {
        return array_column($actions, 'type');
    }

    public function test_viewing_a_profile_is_recorded_once_an_hour_per_person(): void
    {
        $creator = $this->creator();
        $id = $this->profileId($creator);
        $company = $this->createCompany('Acme');
        $priya = $this->createMember($company);
        $sam = $this->createMember($company);

        Sanctum::actingAs($priya);
        $this->getJson("/api/v1/influencers/{$id}")->assertOk();
        $this->getJson("/api/v1/influencers/{$id}")->assertOk(); // a reload is not a new visit
        $this->assertDatabaseCount('profile_views', 1);

        Sanctum::actingAs($sam); // a different person at the same company is
        $this->getJson("/api/v1/influencers/{$id}")->assertOk();
        $this->assertDatabaseCount('profile_views', 2);

        $this->travel(61)->minutes();
        Sanctum::actingAs($priya);
        $this->getJson("/api/v1/influencers/{$id}")->assertOk();
        $this->assertDatabaseCount('profile_views', 3);
        $this->assertDatabaseHas('profile_views', ['influencer_id' => $creator->id, 'company_id' => $company->id, 'user_id' => $priya->id]);
    }

    public function test_sample_profiles_and_hidden_ones_are_not_tracked(): void
    {
        $sample = Influencer::create(['name' => 'Sample Person', 'category' => 'Food', 'country' => 'IN']);
        $sampleProfile = SocialProfile::create([
            'influencer_id' => $sample->id, 'platform' => 'instagram', 'username' => 'sample.person',
            'followers' => 1, 'data_source' => 'sample', 'provider' => 'Sample data',
        ]);
        $hidden = $this->creator('Hidden One');
        $hidden->forceFill(['status' => Influencer::HIDDEN])->save();

        Sanctum::actingAs($this->createMember($this->createCompany()));
        $this->getJson("/api/v1/influencers/{$sampleProfile->id}")->assertOk();
        $this->getJson('/api/v1/influencers/'.$this->profileId($hidden))->assertNotFound();

        $this->assertDatabaseCount('profile_views', 0);
    }

    public function test_the_dashboard_shows_visits_requests_and_recent_visitors(): void
    {
        $creator = $this->creator();
        $acme = $this->createCompany('Acme');
        $globex = $this->createCompany('Globex');

        // Two visits from Acme today and yesterday, one from Globex ten days ago, one too old to count.
        foreach ([[$acme, 0], [$acme, 1], [$globex, 10], [$globex, 45]] as [$company, $daysAgo]) {
            ProfileView::create([
                'influencer_id' => $creator->id, 'company_id' => $company->id, 'user_id' => null,
                'viewed_at' => now()->subDays($daysAgo),
            ]);
        }

        Sanctum::actingAs($creator->user);
        $data = $this->getJson('/api/v1/creator/dashboard')->assertOk()->json('data');

        $this->assertSame(2, $data['views']['last_7_days']);
        $this->assertSame(3, $data['views']['last_30_days']);
        $this->assertSame(2, $data['views']['brands_30_days']);
        $this->assertCount(14, $data['views']['series']);
        $this->assertSame(now()->toDateString(), $data['views']['series'][13]['date']);
        $this->assertSame(1, $data['views']['series'][13]['count']);
        $this->assertSame(1, $data['views']['series'][12]['count']);
        $this->assertSame(['Acme', 'Globex'], array_column($data['recent_visitors'], 'company')); // newest first, one per company
        $this->assertTrue($data['visible_to_brands']);
        $this->assertSame(['pending' => 0, 'accepted' => 0, 'declined' => 0], $data['requests']);
    }

    public function test_the_to_do_list_follows_the_profile_state(): void
    {
        // An incomplete, hidden profile waiting for approval.
        $new = $this->creator('New Person', complete: false);
        $new->forceFill(['approval_status' => ApprovalStatus::PENDING])->save();

        Sanctum::actingAs($new->user);
        $actions = $this->getJson('/api/v1/creator/dashboard')->assertOk()->json('data.actions');
        $this->assertSame(['awaiting_approval', 'complete_profile', 'add_package', 'add_bio'], $this->types($actions));
        $this->assertContains('Choose a category.', $actions[1]['items']);

        // A complete profile that has not been listed yet.
        $ready = $this->creator('Ready Person');
        $ready->forceFill(['status' => Influencer::HIDDEN, 'bio' => null])->save();
        CreatorPackage::create([
            'influencer_id' => $ready->id, 'platform' => 'instagram', 'title' => 'Reel', 'price_minor' => 1000,
            'currency' => 'INR', 'delivery_days' => 3, 'is_active' => true,
        ]);
        Sanctum::actingAs($ready->user);
        $this->assertSame(['list_profile', 'add_bio'], $this->types($this->getJson('/api/v1/creator/dashboard')->json('data.actions')));

        // Everything done and listed: nothing to do. Rejected profiles are told why.
        $done = $this->creator('Done Person');
        CreatorPackage::create([
            'influencer_id' => $done->id, 'platform' => 'instagram', 'title' => 'Reel', 'price_minor' => 1000,
            'currency' => 'INR', 'delivery_days' => 3, 'is_active' => true,
        ]);
        Sanctum::actingAs($done->user);
        $this->assertSame([], $this->getJson('/api/v1/creator/dashboard')->json('data.actions'));

        $done->forceFill(['approval_status' => ApprovalStatus::REJECTED, 'review_note' => 'Add a photo'])->save();
        Sanctum::actingAs(User::findOrFail($done->user_id)); // a fresh user: the old one has the profile cached
        $actions = $this->getJson('/api/v1/creator/dashboard')->json('data.actions');
        $this->assertSame('approval_rejected', $actions[0]['type']);
        $this->assertSame('Add a photo', $actions[0]['note']);
        $this->assertFalse($this->getJson('/api/v1/creator/dashboard')->json('data.visible_to_brands'));
    }

    public function test_requests_waiting_come_first_in_the_to_do_list(): void
    {
        $creator = $this->creator();
        CreatorPackage::create([
            'influencer_id' => $creator->id, 'platform' => 'instagram', 'title' => 'Reel', 'price_minor' => 1000,
            'currency' => 'INR', 'delivery_days' => 3, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->createMember($this->createCompany()));
        $this->postJson('/api/v1/requests', ['creator_id' => $creator->id, 'title' => 'Hello', 'message' => 'Hi'])->assertCreated();

        Sanctum::actingAs($creator->user);
        $actions = $this->getJson('/api/v1/creator/dashboard')->assertOk()->json('data.actions');
        $this->assertSame('answer_requests', $actions[0]['type']);
        $this->assertSame(1, $actions[0]['count']);
        $this->getJson('/api/v1/creator/dashboard')->assertJsonPath('data.requests.pending', 1);
    }

    public function test_only_creators_have_this_dashboard(): void
    {
        Sanctum::actingAs($this->createAdmin($this->createCompany()));
        $this->getJson('/api/v1/creator/dashboard')->assertForbidden();
    }
}
