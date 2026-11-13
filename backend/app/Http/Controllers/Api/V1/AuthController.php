<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\Membership;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Rbac\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** Creates the user, their company and makes them its Company Admin. */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $company = Company::create([
                'name' => $data['company_name'],
                'slug' => Company::uniqueSlug($data['company_name']),
            ]);

            Membership::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'role_id' => Role::findSystem(Role::COMPANY_ADMIN)->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return $user;
        });

        $user->sendEmailVerificationNotification();
        $this->audit->log('auth.registered', $user, actor: $user);

        return $this->tokenResponse($user, $request, 201);
    }

    /** Creates a creator account with an empty, hidden creator profile. */
    public function registerCreator(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->forceFill(['account_type' => 'creator'])->save();

            // Hidden until the creator completes it and chooses to be listed.
            $profile = new Influencer(['name' => $data['name'], 'status' => Influencer::HIDDEN]);
            $profile->user()->associate($user);
            $profile->save();

            return $user;
        });

        $user->sendEmailVerificationNotification();
        $this->audit->log('auth.registered_creator', $user, actor: $user);

        return $this->tokenResponse($user, $request, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower(trim($credentials['email'])))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            if ($user) {
                $this->audit->log('auth.login_failed', $user, actor: $user);
            }

            // Same message for unknown e-mail and wrong password.
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        $this->audit->log('auth.login', $user, actor: $user);

        return $this->tokenResponse($user, $request);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($this->loadForResource($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /** Devices that are currently signed in. */
    public function sessions(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id ?? null;

        $sessions = $request->user()->tokens()->latest('id')->get()->map(fn ($token) => [
            'id' => $token->id,
            'device' => $token->name,
            'current' => $token->id === $currentId,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $sessions]);
    }

    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $request->user()->tokens()->whereKey($id)->delete();

        return response()->json(['message' => 'Session signed out.']);
    }

    /** Target of the link in the verification e-mail (signed, so no login needed). */
    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, 'This verification link is invalid.');

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            $this->audit->log('auth.email_verified', $user, actor: $user);
        }

        return response()->json(['message' => 'Email verified.']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'Verification email sent.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink(['email' => strtolower(trim($request->input('email')))]);

        // Identical response whether or not the account exists.
        return response()->json(['message' => 'If that email is registered, a reset link is on its way.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($data + ['password_confirmation' => $request->input('password_confirmation')], function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete(); // sign out everywhere
            event(new PasswordReset($user));
            $this->audit->log('auth.password_reset', $user, actor: $user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['This reset link is invalid or has expired.']]);
        }

        return response()->json(['message' => 'Password updated. You can sign in now.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = $request->user();
        $user->update(['password' => $data['password']]);

        // Keep this device signed in, sign out the others.
        $currentId = $user->currentAccessToken()->id ?? null;
        $user->tokens()->when($currentId, fn ($q) => $q->whereKeyNot($currentId))->delete();

        $this->audit->log('auth.password_changed', $user);

        return response()->json(['message' => 'Password changed.']);
    }

    public function updateProfile(Request $request): UserResource
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $request->user()->update($data);

        return new UserResource($this->loadForResource($request->user()));
    }

    private function tokenResponse(User $user, Request $request, int $status = 200): JsonResponse
    {
        $device = Str::limit((string) ($request->input('device_name') ?: $request->userAgent() ?: 'Unknown device'), 120, '');

        $token = $user->createToken($device, ['*'], now()->addDays(config('platform.token_ttl_days')));

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user' => new UserResource($this->loadForResource($user)),
            ],
        ], $status);
    }

    private function loadForResource(User $user): User
    {
        return $user->load(['membership.company', 'membership.role.permissions']);
    }
}
