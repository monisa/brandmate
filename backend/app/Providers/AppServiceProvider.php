<?php

namespace App\Providers;

use App\Domain\Companies\TenantContext;
use App\Domain\Search\DatabaseInfluencerSearch;
use App\Domain\Search\InfluencerSearch;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);

        // Swap this binding to move search to OpenSearch/Elasticsearch later.
        $this->app->bind(InfluencerSearch::class, DatabaseInfluencerSearch::class);
    }

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Super Admin passes every Gate/Policy check.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // Login, registration, password reset, invitation lookups.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by(
            strtolower((string) $request->input('email')).'|'.$request->ip()
        ));

        $frontend = config('platform.frontend_url');

        // E-mail links open the Angular app, which then calls the API.
        ResetPassword::createUrlUsing(fn ($user, string $token) => $frontend.'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        VerifyEmail::createUrlUsing(function ($user) use ($frontend) {
            $id = $user->getKey();
            $hash = sha1($user->getEmailForVerification());

            $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $id, 'hash' => $hash]);
            parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

            return $frontend.'/verify-email?'.http_build_query([
                'id' => $id,
                'hash' => $hash,
                'expires' => $query['expires'],
                'signature' => $query['signature'],
            ]);
        });
    }
}
