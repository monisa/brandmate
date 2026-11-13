<?php

namespace Tests\Feature;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function creator(string $name = 'Riya Sen'): Influencer
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['account_type' => 'creator'])->save();

        $creator = new Influencer(['name' => $name, 'category' => 'Food', 'country' => 'IN', 'status' => Influencer::LISTED]);
        $creator->user()->associate($user);
        $creator->save();

        SocialProfile::create([
            'influencer_id' => $creator->id, 'platform' => 'instagram', 'username' => strtolower(str_replace(' ', '.', $name)),
            'followers' => 1000, 'data_source' => 'self_reported', 'provider' => 'Self-reported',
        ]);

        return $creator;
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();

        return $admin;
    }

    private function inbox(User $user): array
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/notifications')->assertOk()->json();
    }

    public function test_a_creator_is_told_about_a_new_request_and_can_mark_it_read(): void
    {
        $creator = $this->creator();
        $brand = $this->createMember($this->createCompany('Acme Foods'));

        Sanctum::actingAs($brand);
        $this->postJson('/api/v1/requests', ['creator_id' => $creator->id, 'title' => 'Festive reel', 'message' => 'Hi'])->assertCreated();

        $inbox = $this->inbox($creator->user);
        $this->assertSame(1, $inbox['unread_count']);
        $this->assertSame('request.received', $inbox['data'][0]['kind']);
        $this->assertSame(['company' => 'Acme Foods', 'title' => 'Festive reel'], $inbox['data'][0]['meta']);
        $this->assertSame('/creator/requests', $inbox['data'][0]['link']);
        $this->assertFalse($inbox['data'][0]['read']);

        $this->postJson('/api/v1/notifications/'.$inbox['data'][0]['id'].'/read')->assertOk()->assertJsonPath('unread_count', 0);
        $after = $this->getJson('/api/v1/notifications')->json();
        $this->assertTrue($after['data'][0]['read']);
        $this->assertSame(0, $after['unread_count']);
    }

    public function test_mark_all_read(): void
    {
        $creator = $this->creator();
        Sanctum::actingAs($this->createMember($this->createCompany()));
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->postJson('/api/v1/requests', ['creator_id' => $creator->id, 'title' => $title, 'message' => 'Hi'])->assertCreated();
        }

        $this->assertSame(3, $this->inbox($creator->user)['unread_count']);
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame(0, $this->getJson('/api/v1/notifications')->json('unread_count'));
    }

    public function test_the_brand_is_told_when_a_creator_answers_or_a_request_is_withdrawn(): void
    {
        $creator = $this->creator();
        $brand = $this->createMember($this->createCompany('Acme Foods'));

        Sanctum::actingAs($brand);
        $answered = $this->postJson('/api/v1/requests', ['creator_id' => $creator->id, 'title' => 'First', 'message' => 'Hi'])->json('data.id');
        $withdrawn = $this->postJson('/api/v1/requests', ['creator_id' => $creator->id, 'title' => 'Second', 'message' => 'Hi'])->json('data.id');
        $this->deleteJson("/api/v1/requests/{$withdrawn}")->assertOk();

        Sanctum::actingAs($creator->user);
        $this->patchJson("/api/v1/creator/requests/{$answered}", ['decision' => 'accepted'])->assertOk();

        $brandInbox = $this->inbox($brand);
        $this->assertSame('request.answered', $brandInbox['data'][0]['kind']);
        $this->assertSame(['creator' => 'Riya Sen', 'title' => 'First', 'decision' => 'accepted'], $brandInbox['data'][0]['meta']);
        $this->assertSame('/requests', $brandInbox['data'][0]['link']);

        // The creator was told about both requests and the withdrawal.
        $kinds = array_column($this->inbox($creator->user)['data'], 'kind');
        $this->assertEquals(['request.received' => 2, 'request.withdrawn' => 1], array_count_values($kinds)); // order is not guaranteed
    }

    public function test_decisions_by_the_super_admin_notify_the_people_affected(): void
    {
        $company = $this->createCompany('Acme');
        $company->forceFill(['approval_status' => ApprovalStatus::PENDING])->save();
        $owner = $this->createAdmin($company);
        $teammate = $this->createMember($company);
        $creator = $this->creator();
        $outsider = $this->createMember($this->createCompany('Other'));

        Sanctum::actingAs($this->superAdmin());
        $this->postJson("/api/v1/admin/companies/{$company->id}/review", ['decision' => 'approved', 'note' => 'Welcome'])->assertOk();
        $this->postJson("/api/v1/admin/creators/{$creator->id}/review", ['decision' => 'rejected', 'note' => 'Photos missing'])->assertOk();

        foreach ([$owner, $teammate] as $member) {
            $inbox = $this->inbox($member);
            $this->assertSame('company.approved', $inbox['data'][0]['kind']);
            $this->assertSame(['note' => 'Welcome'], $inbox['data'][0]['meta']);
        }
        $creatorInbox = $this->inbox($creator->user);
        $this->assertSame('creator.rejected', $creatorInbox['data'][0]['kind']);
        $this->assertSame(['note' => 'Photos missing'], $creatorInbox['data'][0]['meta']);
        $this->assertSame('/creator/profile', $creatorInbox['data'][0]['link']);

        $this->assertSame([], $this->inbox($outsider)['data']); // nobody else heard about it
    }

    public function test_people_only_see_and_change_their_own_notifications(): void
    {
        $creator = $this->creator();
        Sanctum::actingAs($this->createMember($this->createCompany()));
        $this->postJson('/api/v1/requests', ['creator_id' => $creator->id, 'title' => 'Hello', 'message' => 'Hi'])->assertCreated();
        $id = $this->inbox($creator->user)['data'][0]['id'];

        $stranger = $this->creator('Ben Lim');
        Sanctum::actingAs($stranger->user);
        $this->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
        $this->postJson('/api/v1/notifications/not-a-uuid/read')->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/notifications')->json('data'));

        $this->assertSame(1, $this->inbox($creator->user)['unread_count']); // untouched
    }
}
