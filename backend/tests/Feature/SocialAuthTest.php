<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'google-id',
            'services.google.client_secret' => 'google-secret',
            'platform.frontend_url' => 'https://app.test',
        ]);
    }

    private function startGoogle(): string
    {
        $location = $this->get('/api/v1/auth/oauth/google')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function fakeGoogle(string $email, bool $verified = true): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'abc']),
            'openidconnect.googleapis.com/*' => Http::response(['email' => $email, 'email_verified' => $verified, 'name' => 'Asha']),
        ]);
    }

    public function test_providers_report_what_is_configured(): void
    {
        $this->getJson('/api/v1/auth/oauth/providers')
            ->assertOk()->assertJsonPath('data.google', true)->assertJsonPath('data.facebook', false);
    }

    public function test_an_existing_user_signs_in_with_google(): void
    {
        $user = User::factory()->create(['email' => 'asha@example.com', 'email_verified_at' => null]);
        $state = $this->startGoogle();
        $this->fakeGoogle('Asha@Example.com');

        $location = $this->get("/api/v1/auth/oauth/google/callback?state={$state}&code=xyz")
            ->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://app.test/oauth/callback?code=', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->postJson('/api/v1/auth/oauth/exchange', ['code' => $query['code']])
            ->assertOk()->assertJsonPath('data.user.email', 'asha@example.com')->assertJsonStructure(['data' => ['token']]);
        $this->assertNotNull($user->fresh()->email_verified_at);

        // The code works once only.
        $this->postJson('/api/v1/auth/oauth/exchange', ['code' => $query['code']])->assertUnprocessable();
    }

    public function test_unknown_email_is_sent_back_to_login(): void
    {
        $state = $this->startGoogle();
        $this->fakeGoogle('nobody@example.com');

        $this->get("/api/v1/auth/oauth/google/callback?state={$state}&code=xyz")
            ->assertRedirect('https://app.test/login?oauth_error=no_account&provider=google');
    }

    public function test_unverified_google_email_is_refused(): void
    {
        User::factory()->create(['email' => 'asha@example.com']);
        $state = $this->startGoogle();
        $this->fakeGoogle('asha@example.com', verified: false);

        $this->get("/api/v1/auth/oauth/google/callback?state={$state}&code=xyz")
            ->assertRedirect('https://app.test/login?oauth_error=no_email&provider=google');
    }

    public function test_a_forged_or_reused_state_is_refused(): void
    {
        $this->get('/api/v1/auth/oauth/google/callback?state=forged&code=xyz')
            ->assertRedirect('https://app.test/login?oauth_error=expired&provider=google');

        $state = $this->startGoogle();
        $this->fakeGoogle('nobody@example.com');
        $this->get("/api/v1/auth/oauth/google/callback?state={$state}&code=xyz");
        $this->get("/api/v1/auth/oauth/google/callback?state={$state}&code=xyz")
            ->assertRedirect('https://app.test/login?oauth_error=expired&provider=google');
    }

    public function test_unconfigured_provider_explains_itself(): void
    {
        $this->get('/api/v1/auth/oauth/facebook')
            ->assertRedirect('https://app.test/login?oauth_error=not_configured&provider=facebook');
    }
}
