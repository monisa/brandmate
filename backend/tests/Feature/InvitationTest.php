<?php

namespace Tests\Feature;

use App\Domain\Companies\Models\Invitation;
use App\Domain\Companies\Notifications\InvitationNotification;
use App\Domain\Rbac\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_invite_and_the_invitee_joins_the_same_company(): void
    {
        Notification::fake();

        $company = $this->createCompany('Bright Labs');
        Sanctum::actingAs($this->createAdmin($company));

        $this->postJson('/api/v1/team/invitations', [
            'email' => 'new@example.com',
            'role_id' => Role::findSystem(Role::TEAM_MEMBER)->id,
        ])->assertCreated()->assertJsonPath('data.email', 'new@example.com');

        // Pull the raw token out of the e-mailed link.
        $token = null;
        Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $n) use (&$token) {
            parse_str((string) parse_url($n->acceptUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return $token !== null;
        });


        $this->getJson("/api/v1/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('data.company', 'Bright Labs');

        $this->postJson("/api/v1/invitations/{$token}/accept", [
            'name' => 'New Person',
            'password' => 'secret-pass1',
            'password_confirmation' => 'secret-pass1',
        ])->assertCreated()
            ->assertJsonPath('data.user.company.id', $company->id)
            ->assertJsonPath('data.user.role.slug', 'team_member')
            ->assertJsonPath('data.user.email_verified', true);

        // A token works once.
        $this->getJson("/api/v1/invitations/{$token}")->assertNotFound();
    }

    public function test_expired_invitations_cannot_be_used(): void
    {
        Invitation::create([
            'company_id' => $this->createCompany()->id,
            'email' => 'late@example.com',
            'role_id' => Role::findSystem(Role::TEAM_MEMBER)->id,
            'token_hash' => Invitation::hashToken('expired-token'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v1/invitations/expired-token')->assertNotFound();
    }
}
