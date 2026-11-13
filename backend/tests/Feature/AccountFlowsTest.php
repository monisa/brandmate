<?php

namespace Tests\Feature;

use App\Domain\Authentication\Notifications\ResetPasswordNotification;
use App\Domain\Authentication\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountFlowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_emailed_link_verifies_the_address(): void
    {
        $user = $this->createAdmin($this->createCompany(), ['email_verified_at' => null]);

        // The e-mail points at the Angular app, which forwards these four values to the API.
        $link = (new VerifyEmailNotification)->toMail($user)->actionUrl;
        $this->assertStringStartsWith(config('platform.frontend_url').'/verify-email?', $link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        $this->getJson("/api/v1/auth/email/verify/{$q['id']}/{$q['hash']}?expires={$q['expires']}&signature={$q['signature']}")
            ->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_tampered_verification_link_is_rejected(): void
    {
        $user = $this->createAdmin($this->createCompany(), ['email_verified_at' => null]);

        $this->getJson("/api/v1/auth/email/verify/{$user->id}/".sha1($user->email).'?expires=9999999999&signature=forged')
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_password_can_be_reset_with_the_emailed_token(): void
    {
        Notification::fake();
        $user = $this->createAdmin($this->createCompany(), ['email' => 'a@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'a@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'a@example.com',
            'password' => 'brand-new-pass1',
            'password_confirmation' => 'brand-new-pass1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'brand-new-pass1'])->assertOk();
    }

    public function test_forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_changing_the_password_signs_out_other_devices(): void
    {
        $this->createAdmin($this->createCompany(), ['email' => 'a@example.com', 'password' => 'secret-pass1']);
        $login = fn (string $password) => $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => $password]);

        $thisDevice = $login('secret-pass1')->json('data.token');
        $otherDevice = $login('secret-pass1')->json('data.token');

        $this->withToken($thisDevice)->getJson('/api/v1/auth/sessions')->assertOk()->assertJsonCount(2, 'data');

        $this->withToken($thisDevice)->putJson('/api/v1/auth/password', [
            'current_password' => 'secret-pass1',
            'password' => 'brand-new-pass1',
            'password_confirmation' => 'brand-new-pass1',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->withToken($otherDevice)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $login('brand-new-pass1')->assertOk();
    }

    public function test_logout_revokes_the_token(): void
    {
        $this->createAdmin($this->createCompany(), ['email' => 'a@example.com', 'password' => 'secret-pass1']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'secret-pass1'])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
