<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Companies\Models\Membership;
use App\Domain\Rbac\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        // Membership is tenant-scoped: only the current company's members come back.
        $members = Membership::with(['user', 'role'])->orderBy('id')->paginate($this->perPage($request));

        return MemberResource::collection($members);
    }

    public function roles(): JsonResponse
    {
        $roles = Role::query()->system()->with('permissions')->orderBy('id')->get()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'permissions' => $role->permissions->pluck('slug')->values(),
        ]);

        return response()->json(['data' => $roles]);
    }

    public function update(Request $request, int $userId): MemberResource
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereNull('company_id')],
        ]);

        $membership = Membership::where('user_id', $userId)->firstOrFail(); // 404 for other tenants

        if ((int) $data['role_id'] !== (int) $membership->role_id) {
            $this->guardLastAdmin($membership);

            $from = $membership->role->slug;
            $membership->update(['role_id' => $data['role_id']]);
            $membership->load('role');

            $this->audit->log('team.role_changed', $membership->user, ['from' => $from, 'to' => $membership->role->slug]);
        }

        return new MemberResource($membership->load('user'));
    }

    public function destroy(Request $request, int $userId): JsonResponse
    {
        $membership = Membership::where('user_id', $userId)->firstOrFail();

        if ((int) $membership->user_id === (int) $request->user()->id) {
            throw ValidationException::withMessages(['member' => ['You cannot remove yourself from the company.']]);
        }

        $this->guardLastAdmin($membership);

        $user = $membership->user;
        $membership->delete();
        $user->tokens()->delete();

        $this->audit->log('team.member_removed', $user, ['email' => $user->email]);

        return response()->json(['message' => 'Member removed.']);
    }

    /** A company must always keep at least one Company Admin. */
    private function guardLastAdmin(Membership $membership): void
    {
        $adminRoleId = Role::findSystem(Role::COMPANY_ADMIN)->id;

        if ((int) $membership->role_id !== (int) $adminRoleId) {
            return;
        }

        $admins = Membership::where('role_id', $adminRoleId)->where('status', 'active')->count();

        if ($admins <= 1) {
            throw ValidationException::withMessages(['member' => ['A company needs at least one Company Admin.']]);
        }
    }
}
