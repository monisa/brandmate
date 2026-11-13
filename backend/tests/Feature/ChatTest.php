<?php

namespace Tests\Feature;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Companies\Models\Company;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use App\Domain\Subscriptions\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatTest extends TestCase
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
            'influencer_id' => $creator->id, 'platform' => 'instagram', 'username' => 'riya.eats',
            'followers' => 5000, 'data_source' => 'self_reported', 'provider' => 'Self-reported',
        ]);

        return $creator;
    }

    private function subscribe(Company|User $subscriber, string $status = Subscription::ACTIVE): void
    {
        Subscription::updateOrCreate([($subscriber instanceof Company ? 'company_id' : 'user_id') => $subscriber->id], [
            'status' => $status, 'gateway' => 'manual', 'price_minor' => 99900, 'gst_minor' => 17982, 'total_minor' => 117882,
            'currency' => 'INR', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addDays(20),
        ]);
    }

    /** A brand member starts a chat with the creator and returns the conversation id. */
    private function open(User $brand, Influencer $creator): int
    {
        Sanctum::actingAs($brand);

        return $this->postJson('/api/v1/chat/conversations', ['creator_id' => $creator->id])->json('data.id');
    }

    public function test_a_brand_starts_a_chat_and_both_sides_see_it(): void
    {
        $creator = $this->creator();
        $brand = $this->createMember($this->createCompany('Acme Foods'));

        Sanctum::actingAs($brand);
        $first = $this->postJson('/api/v1/chat/conversations', ['creator_id' => $creator->id])->assertCreated()
            ->assertJsonPath('data.counterpart.name', 'Riya Sen')->assertJsonPath('data.counterpart.kind', 'creator')
            ->assertJsonPath('data.unread_count', 0)->assertJsonPath('data.last_message', null);
        $id = $first->json('data.id');

        // Starting again returns the same conversation.
        $this->postJson('/api/v1/chat/conversations', ['creator_id' => $creator->id])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('conversations', 1);

        Sanctum::actingAs($creator->user);
        $this->getJson('/api/v1/chat/conversations')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.counterpart.name', 'Acme Foods')->assertJsonPath('data.0.counterpart.kind', 'brand');
    }

    public function test_messages_unread_counts_and_polling(): void
    {
        $creator = $this->creator();
        $company = $this->createCompany('Acme Foods');
        $priya = $this->createMember($company, attributes: ['name' => 'Priya']);
        $id = $this->open($priya, $creator);

        Sanctum::actingAs($priya);
        $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => '  Hello there  '])->assertCreated()
            ->assertJsonPath('data.body', 'Hello there')->assertJsonPath('data.from_me', true)->assertJsonPath('data.sender', 'Priya');
        $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => 'Are you free in December?'])->assertCreated();
        $this->getJson('/api/v1/chat/conversations')->assertJsonPath('data.0.unread_count', 0); // your own messages are read

        // The creator has two unread messages, sees the latest as the preview, and reading clears them.
        Sanctum::actingAs($creator->user);
        $list = $this->getJson('/api/v1/chat/conversations')->assertOk();
        $list->assertJsonPath('data.0.unread_count', 2)->assertJsonPath('unread_total', 2)
            ->assertJsonPath('data.0.last_message.body', 'Are you free in December?')->assertJsonPath('data.0.last_message.from_me', false);
        $this->getJson('/api/v1/chat/unread')->assertJsonPath('unread_total', 2);

        $read = $this->getJson("/api/v1/chat/conversations/{$id}/messages")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['Hello there', 'Are you free in December?'], array_column($read->json('data'), 'body')); // oldest first
        $this->assertFalse($read->json('data.0.from_me'));
        $this->getJson('/api/v1/chat/unread')->assertJsonPath('unread_total', 0);

        // The creator replies; the brand polls for only what is new.
        $reply = $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => 'Yes, send details'])->assertCreated()->json('data.id');
        Sanctum::actingAs($priya);
        $this->getJson('/api/v1/chat/unread')->assertJsonPath('unread_total', 1);
        $lastSeen = $read->json('data.1.id');
        $new = $this->getJson("/api/v1/chat/conversations/{$id}/messages?after={$lastSeen}")->assertOk()->assertJsonCount(1, 'data');
        $new->assertJsonPath('data.0.id', $reply)->assertJsonPath('data.0.from_me', false);
        $this->getJson("/api/v1/chat/conversations/{$id}/messages?after={$reply}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/chat/unread')->assertJsonPath('unread_total', 0);
    }

    public function test_the_whole_brand_team_shares_one_conversation(): void
    {
        $creator = $this->creator();
        $company = $this->createCompany();
        $priya = $this->createMember($company, attributes: ['name' => 'Priya']);
        $sam = $this->createMember($company, attributes: ['name' => 'Sam']);
        $id = $this->open($priya, $creator);
        $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => 'From Priya'])->assertCreated();

        Sanctum::actingAs($sam);
        $this->getJson('/api/v1/chat/conversations')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/chat/conversations/{$id}/messages")->assertJsonPath('data.0.sender', 'Priya')->assertJsonPath('data.0.from_me', true);
        $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => 'From Sam'])->assertCreated()->assertJsonPath('data.sender', 'Sam');
    }

    public function test_nobody_outside_a_conversation_can_read_or_write_it(): void
    {
        $creator = $this->creator('Riya Sen');
        $id = $this->open($this->createMember($this->createCompany('Acme')), $creator);

        $strangers = [
            $this->createMember($this->createCompany('Globex')),
            $this->creator('Ben Lim')->user,
        ];
        foreach ($strangers as $stranger) {
            Sanctum::actingAs($stranger);
            $this->getJson("/api/v1/chat/conversations/{$id}/messages")->assertNotFound();
            $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => 'hi'])->assertNotFound();
            $this->getJson('/api/v1/chat/conversations')->assertOk()->assertJsonCount(0, 'data');
        }

        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/chat/conversations')->assertForbidden();
    }

    public function test_only_approved_participants_can_use_chat(): void
    {
        $creator = $this->creator();
        $pending = $this->createCompany('Pending Co');
        $pending->forceFill(['approval_status' => ApprovalStatus::PENDING])->save();

        Sanctum::actingAs($this->createMember($pending));
        $this->getJson('/api/v1/chat/conversations')->assertForbidden();
        $this->postJson('/api/v1/chat/conversations', ['creator_id' => $creator->id])->assertForbidden();

        $creator->forceFill(['approval_status' => ApprovalStatus::PENDING])->save();
        Sanctum::actingAs(User::findOrFail($creator->user_id));
        $this->getJson('/api/v1/chat/conversations')->assertForbidden();
    }

    public function test_a_creator_can_start_a_chat_with_a_listed_brand_only(): void
    {
        $creator = $this->creator();
        $brand = $this->createCompany('Acme Foods');
        $pending = $this->createCompany('Pending Co');
        $pending->forceFill(['approval_status' => ApprovalStatus::PENDING])->save();
        $suspended = $this->createCompany('Suspended Co');
        $suspended->forceFill(['status' => 'suspended'])->save();

        Sanctum::actingAs($creator->user);
        $this->postJson('/api/v1/chat/conversations', ['company_id' => $brand->id])->assertCreated()
            ->assertJsonPath('data.counterpart.name', 'Acme Foods')->assertJsonPath('data.counterpart.kind', 'brand');
        $this->postJson('/api/v1/chat/conversations', ['company_id' => $pending->id])->assertNotFound();
        $this->postJson('/api/v1/chat/conversations', ['company_id' => $suspended->id])->assertNotFound();
        $this->postJson('/api/v1/chat/conversations', [])->assertUnprocessable();
    }

    public function test_a_hidden_creator_cannot_be_messaged(): void
    {
        $hidden = $this->creator();
        $hidden->forceFill(['status' => Influencer::HIDDEN])->save();

        Sanctum::actingAs($this->createMember($this->createCompany()));
        $this->postJson('/api/v1/chat/conversations', ['creator_id' => $hidden->id])->assertNotFound();
    }

    public function test_message_text_is_validated(): void
    {
        $creator = $this->creator();
        $brand = $this->createMember($this->createCompany());
        $id = $this->open($brand, $creator);

        foreach ([null, '', '   ', str_repeat('x', 2001)] as $body) {
            $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => $body])->assertUnprocessable()->assertJsonValidationErrors('body');
        }
        $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => str_repeat('x', 2000)])->assertCreated();
        $this->assertDatabaseCount('messages', 1);
    }

    // ---- Subscriptions ----

    public function test_when_subscriptions_are_enforced_both_sides_must_be_subscribed_to_start(): void
    {
        config(['subscription.enforced' => true]);
        $creator = $this->creator();
        $company = $this->createCompany();
        $brand = $this->createMember($company);

        Sanctum::actingAs($brand);
        $this->postJson('/api/v1/chat/conversations', ['creator_id' => $creator->id])
            ->assertForbidden()->assertJsonPath('code', 'subscription_required');

        $this->subscribe($company);
        Sanctum::actingAs($this->createMember($company));
        $this->postJson('/api/v1/chat/conversations', ['creator_id' => $creator->id])
            ->assertStatus(422)->assertJsonPath('code', 'counterpart_not_subscribed');

        $this->subscribe($creator->user);
        Sanctum::actingAs($this->createMember($company));
        $this->postJson('/api/v1/chat/conversations', ['creator_id' => $creator->id])->assertCreated();

        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_a_lapsed_subscription_stops_sending_but_not_reading(): void
    {
        config(['subscription.enforced' => true]);
        $creator = $this->creator();
        $company = $this->createCompany();
        $this->subscribe($company);
        $this->subscribe($creator->user);
        $id = $this->open($this->createMember($company), $creator);
        $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => 'Hello'])->assertCreated();

        // The creator's subscription ends: the brand can read but not write.
        $this->subscribe($creator->user, Subscription::EXPIRED);
        Sanctum::actingAs($this->createMember($company));
        $this->getJson("/api/v1/chat/conversations/{$id}/messages")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.can_send', false)->assertJsonPath('meta.reason', 'counterpart_not_subscribed');
        $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => 'Anyone there?'])
            ->assertStatus(422)->assertJsonPath('code', 'counterpart_not_subscribed');

        // The creator, unsubscribed, can still read what the brand wrote but cannot answer.
        Sanctum::actingAs(User::findOrFail($creator->user_id));
        $this->getJson("/api/v1/chat/conversations/{$id}/messages")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.can_send', false)->assertJsonPath('meta.reason', 'subscription_required');
        $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => 'Hi'])
            ->assertForbidden()->assertJsonPath('code', 'subscription_required');

        // Subscribed again, both sides can write.
        $this->subscribe($creator->user);
        Sanctum::actingAs(User::findOrFail($creator->user_id));
        $this->getJson("/api/v1/chat/conversations/{$id}/messages")->assertJsonPath('meta.can_send', true);
        $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => 'Hi again'])->assertCreated();
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_deleting_a_creator_removes_their_conversations(): void
    {
        $creator = $this->creator();
        $id = $this->open($this->createMember($this->createCompany()), $creator);
        $this->postJson("/api/v1/chat/conversations/{$id}/messages", ['body' => 'Hello'])->assertCreated();

        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/creators/bulk-delete', ['ids' => [$creator->id]])->assertOk();

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }
}
