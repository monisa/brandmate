<?php

namespace Tests\Feature;

use App\Domain\Support\Ticket;
use App\Mail\SupportUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['email' => 'boss@brandmate.test']);
        $admin->forceFill(['is_super_admin' => true])->save();

        return $admin;
    }

    public function test_a_person_raises_a_ticket_and_the_team_is_e_mailed(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Riya']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/support/tickets', ['subject' => 'Payment problem', 'message' => 'I paid but it did not unlock', 'category' => 'payment'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.replies.0.author', 'You');

        Mail::assertSent(SupportUpdate::class, fn ($m) => $m->hasTo($admin->email) && $m->direction === 'to_support');
        $this->getJson('/api/v1/support/tickets')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_ticket_goes_to_the_support_address_when_one_is_set(): void
    {
        Mail::fake();
        $this->admin();
        config(['platform.support_email' => 'help@brandmate.test']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/support/tickets', ['subject' => 'Hello', 'message' => 'Need some help'])->assertCreated();

        Mail::assertSent(SupportUpdate::class, 1);
        Mail::assertSent(SupportUpdate::class, fn ($m) => $m->hasTo('help@brandmate.test'));
    }

    public function test_nobody_can_read_or_write_someone_else_s_ticket(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/v1/support/tickets', ['subject' => 'Private', 'message' => 'Only for support'])->json('data.id');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/support/tickets/{$id}")->assertNotFound();
        $this->postJson("/api/v1/support/tickets/{$id}/replies", ['body' => 'hi'])->assertNotFound();
        $this->getJson('/api/v1/support/tickets')->assertJsonCount(0, 'data');
    }

    public function test_support_replies_reach_the_person_and_their_answer_reopens_the_ticket(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $user = User::factory()->create(['email' => 'riya@example.test']);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/support/tickets', ['subject' => 'Help', 'message' => 'Cannot find my profile'])->json('data.id');

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/support/tickets/{$id}/replies", ['body' => 'It is waiting for approval.'])
            ->assertCreated()->assertJsonPath('data.status', 'answered');
        Mail::assertSent(SupportUpdate::class, fn ($m) => $m->hasTo('riya@example.test') && $m->direction === 'to_user');
        $this->patchJson("/api/v1/admin/support/tickets/{$id}", ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/support/tickets/{$id}/replies", ['body' => 'Thanks, one more question'])->assertCreated()->assertJsonPath('data.status', 'open');
        $this->getJson("/api/v1/support/tickets/{$id}")->assertJsonPath('data.replies.1.author', 'Support');
    }

    public function test_the_admin_list_is_for_platform_admins_only(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/support/tickets')->assertForbidden();
        $this->postJson('/api/v1/admin/support/tickets/1/replies', ['body' => 'x'])->assertForbidden();
    }

    public function test_a_ticket_needs_a_subject_and_a_message_and_unverified_people_cannot_raise_one(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/support/tickets', ['subject' => '', 'message' => ''])->assertUnprocessable();
        $this->postJson('/api/v1/support/tickets', ['subject' => 'Hi there', 'message' => 'Long enough', 'category' => 'hacking'])->assertUnprocessable();

        Sanctum::actingAs(User::factory()->unverified()->create());
        $this->postJson('/api/v1/support/tickets', ['subject' => 'Hi there', 'message' => 'Long enough'])->assertForbidden();
        $this->assertSame(0, Ticket::count());
    }
}
