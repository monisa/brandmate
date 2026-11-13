<?php

namespace Tests\Feature;

use App\Domain\Authentication\Notifications\VerifyEmailNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_user_company_and_admin_membership(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Asha Menon',
            'email' => 'Asha@Example.com',
            'password' => 'secret-pass1',
            'password_confirmation' => 'secret-pass1',
            'company_name' => 'Bright Labs',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'asha@example.com')
            ->assertJsonPath('data.user.company.name', 'Bright Labs')
            ->assertJsonPath('data.user.role.slug', 'company_admin')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);

        $this->assertContains('team.manage', $response->json('data.user.permissions'));
        $this->assertDatabaseHas('companies', ['name' => 'Bright Labs']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.registered']);

        Notification::assertSentTo(User::firstWhere('email', 'asha@example.com'), VerifyEmailNotification::class);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'X', 'email' => 'taken@example.com', 'company_name' => 'Y',
            'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_returns_a_token_that_authenticates(): void
    {
        $user = $this->createAdmin($this->createCompany(), ['email' => 'a@example.com', 'password' => 'secret-pass1']);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'secret-pass1'])
            ->assertOk()
            ->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        $this->createAdmin($this->createCompany(), ['email' => 'a@example.com', 'password' => 'secret-pass1']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/team/members')->assertUnauthorized();
    }

    public function test_unverified_users_cannot_use_the_workspace(): void
    {
        $user = $this->createAdmin($this->createCompany(), ['email_verified_at' => null]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/company');

        $this->assertContains($response->status(), [403, 409]);
        $response->assertJsonPath('message', 'Your email address is not verified.');
    }

    public function test_every_response_carries_a_request_id(): void
    {
        $this->getJson('/api/v1/auth/me')->assertHeader('X-Request-Id');
    }
}
