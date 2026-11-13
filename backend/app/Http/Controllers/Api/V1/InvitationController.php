<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Companies\CompanyScope;
use App\Domain\Companies\Models\Invitation;
use App\Domain\Companies\Models\Membership;
use App\Domain\Companies\Notifications\InvitationNotification;
use App\Domain\Companies\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $invitations = Invitation::pending()->with(['role', 'inviter'])->latest('id')->paginate($this->perPage($request));

        return InvitationResource::collection($invitations);
    }

    public function store(Request $request, TenantContext $tenant): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereNull('company_id')],
        ]);

        // MVP: one company per user. Phase 3 adds the plan's team-member limit here.
        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => ['This person already has an account.']]);
        }

        $token = Str::random(64);
        $days = config('platform.invitation_ttl_days');

        $invitation = DB::transaction(function () use ($data, $token, $days, $request) {
            Invitation::where('email', $data['email'])->whereNull('accepted_at')->delete(); // replace older invite

            return Invitation::create([
                'email' => $data['email'],
                'role_id' => $data['role_id'],
                'token_hash' => Invitation::hashToken($token),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays($days),
            ]);
        });

        Notification::route('mail', $invitation->email)->notify(new InvitationNotification(
            $tenant->company()->name,
            $request->user()->name,
            config('platform.frontend_url').'/accept-invite?token='.$token,
            $days,
        ));

        $this->audit->log('team.invited', $invitation, ['email' => $invitation->email]);

        return (new InvitationResource($invitation->load(['role', 'inviter'])))->response()->setStatusCode(201);
    }

    public function destroy(int $id): JsonResponse
    {
        $invitation = Invitation::findOrFail($id); // tenant-scoped

        $invitation->delete();
        $this->audit->log('team.invitation_revoked', null, ['email' => $invitation->email]);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /** Public: lets the accept page show who is inviting before a password is chosen. */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->findByToken($token);

        return response()->json(['data' => [
            'email' => $invitation->email,
            'company' => $invitation->company->name,
            'role' => $invitation->role->name,
        ]]);
    }

    /** Public: creates the invited user's account inside the inviting company. */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->findByToken($token);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (User::where('email', $invitation->email)->exists()) {
            throw ValidationException::withMessages(['email' => ['An account with this email already exists.']]);
        }

        $user = DB::transaction(function () use ($invitation, $data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
            ]);

            // Opening the e-mailed link proves ownership of the address.
            $user->forceFill(['email_verified_at' => now()])->save();

            Membership::create([
                'company_id' => $invitation->company_id,
                'user_id' => $user->id,
                'role_id' => $invitation->role_id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        $this->audit->log('team.invitation_accepted', $user, actor: $user, companyId: $invitation->company_id);

        $accessToken = $user->createToken(
            Str::limit((string) ($request->userAgent() ?: 'Unknown device'), 120, ''),
            ['*'],
            now()->addDays(config('platform.token_ttl_days')),
        );

        return response()->json(['data' => [
            'token' => $accessToken->plainTextToken,
            'expires_at' => $accessToken->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user->load(['membership.company', 'membership.role.permissions'])),
        ]], 201);
    }

    private function findByToken(string $token): Invitation
    {
        // No tenant exists yet for an invitee; the unguessable token is the credential.
        return Invitation::withoutGlobalScope(CompanyScope::class)
            ->pending()
            ->with(['company', 'role'])
            ->where('token_hash', Invitation::hashToken($token))
            ->firstOrFail();
    }
}
