<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Continue with Google / Facebook" for existing accounts.
 *
 * 1. GET  /auth/oauth/{provider}            -> redirect to the provider (one-time state in cache)
 * 2. GET  /auth/oauth/{provider}/callback   -> code for the provider's profile; the verified
 *                                              e-mail must belong to an existing user
 * 3. Redirect to <frontend>/oauth/callback?code=...   (a 60-second, single-use code)
 * 4. POST /auth/oauth/exchange {code}       -> the same session payload as password login
 *
 * The API token never appears in a URL. New accounts are not created here because
 * registration also sets up a company or creator profile.
 */
class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'facebook'];

    private const FACEBOOK_GRAPH = 'https://graph.facebook.com/v21.0';

    public function __construct(private AuditLogger $audit) {}

    /** Which providers have credentials configured, so the app can show their buttons. */
    public function providers(): JsonResponse
    {
        return response()->json(['data' => collect(self::PROVIDERS)
            ->mapWithKeys(fn ($p) => [$p => $this->configured($p)])]);
    }

    public function redirect(string $provider): RedirectResponse
    {
        if (! $this->configured($provider)) {
            return $this->fail('not_configured', $provider);
        }

        $state = Str::random(40);
        Cache::put("oauth:state:{$state}", $provider, now()->addMinutes(10));

        $url = match ($provider) {
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
                'client_id' => config('services.google.client_id'),
                'redirect_uri' => $this->callbackUrl('google'),
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
                'prompt' => 'select_account',
            ]),
            'facebook' => 'https://www.facebook.com/v21.0/dialog/oauth?'.http_build_query([
                'client_id' => config('services.facebook.client_id'),
                'redirect_uri' => $this->callbackUrl('facebook'),
                'response_type' => 'code',
                'scope' => 'email,public_profile',
                'state' => $state,
            ]),
        };

        return redirect()->away($url);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $state = (string) $request->query('state');
        if ($state === '' || Cache::pull("oauth:state:{$state}") !== $provider) {
            return $this->fail('expired', $provider);
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->fail('cancelled', $provider);
        }

        try {
            $profile = $this->profile($provider, (string) $request->query('code'));
        } catch (Throwable $e) {
            report($e);

            return $this->fail('failed', $provider);
        }

        if (! $profile['email'] || ! $profile['verified']) {
            return $this->fail('no_email', $provider);
        }

        $user = User::where('email', strtolower($profile['email']))->first();
        if (! $user) {
            return $this->fail('no_account', $provider);
        }

        if (! $user->email_verified_at) {
            // The provider has confirmed the address, which is what verification proves.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $code = Str::random(48);
        Cache::put("oauth:code:{$code}", ['user_id' => $user->id, 'provider' => $provider], now()->addSeconds(60));

        return redirect()->away(config('platform.frontend_url').'/oauth/callback?'.http_build_query(['code' => $code]));
    }

    /** Trades the one-time code for an API token, like a password sign-in. */
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $entry = Cache::pull("oauth:code:{$data['code']}");
        $user = $entry ? User::find($entry['user_id']) : null;

        if (! $user) {
            return response()->json(['message' => 'This sign-in link has expired. Please try again.'], 422);
        }

        $this->audit->log('auth.login', $user, ['method' => $entry['provider']], actor: $user);

        $device = Str::limit((string) ($request->input('device_name') ?: $request->userAgent() ?: 'Unknown device'), 120, '');
        $token = $user->createToken($device, ['*'], now()->addDays(config('platform.token_ttl_days')));

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user->load(['membership.company', 'membership.role.permissions'])),
        ]]);
    }

    /** @return array{email: ?string, verified: bool, name: ?string} */
    private function profile(string $provider, string $code): array
    {
        if ($provider === 'google') {
            $token = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => $this->callbackUrl('google'),
                'grant_type' => 'authorization_code',
            ])->throw()->json('access_token');

            $info = Http::withToken($token)->timeout(15)
                ->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json();

            return ['email' => $info['email'] ?? null, 'verified' => (bool) ($info['email_verified'] ?? false), 'name' => $info['name'] ?? null];
        }

        $token = Http::timeout(15)->get(self::FACEBOOK_GRAPH.'/oauth/access_token', [
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'redirect_uri' => $this->callbackUrl('facebook'),
            'code' => $code,
        ])->throw()->json('access_token');

        $info = Http::timeout(15)->get(self::FACEBOOK_GRAPH.'/me', [
            'fields' => 'id,name,email',
            'access_token' => $token,
        ])->throw()->json();

        // Facebook only returns e-mail addresses the person has confirmed.
        return ['email' => $info['email'] ?? null, 'verified' => isset($info['email']), 'name' => $info['name'] ?? null];
    }

    private function configured(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true)
            && config("services.{$provider}.client_id") && config("services.{$provider}.client_secret");
    }

    private function callbackUrl(string $provider): string
    {
        return route('oauth.callback', ['provider' => $provider]);
    }

    private function fail(string $reason, string $provider): RedirectResponse
    {
        return redirect()->away(config('platform.frontend_url').'/login?'.http_build_query([
            'oauth_error' => $reason,
            'provider' => in_array($provider, self::PROVIDERS, true) ? $provider : null,
        ]));
    }
}
