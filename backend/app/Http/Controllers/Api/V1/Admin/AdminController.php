<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Companies\CompanyScope;
use App\Domain\Companies\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Platform-wide screens for the Super Admin. Every query here is
 * intentionally cross-tenant; the routes are behind `super_admin`.
 */
class AdminController extends Controller
{
    public function overview(): JsonResponse
    {
        return response()->json(['data' => [
            'companies' => Company::count(),
            'suspended_companies' => Company::where('status', 'suspended')->count(),
            'users' => User::count(),
            'unverified_users' => User::whereNull('email_verified_at')->count(),
        ]]);
    }

    public function companies(Request $request): AnonymousResourceCollection
    {
        $companies = Company::withCount('memberships')
            ->when($request->query('search'), fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->latest('id')
            ->paginate($this->perPage($request));

        return CompanyResource::collection($companies);
    }

    public function updateCompany(Request $request, int $id, AuditLogger $audit): CompanyResource
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);

        $company = Company::findOrFail($id);
        $company->update($data);
        $audit->log('admin.company_status_changed', $company, $data, companyId: $company->id);

        return new CompanyResource($company->loadCount('memberships'));
    }

    public function users(Request $request): AnonymousResourceCollection
    {
        $users = User::with(['membership.company', 'membership.role.permissions'])
            ->when($request->query('search'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')
            ))
            ->latest('id')
            ->paginate($this->perPage($request));

        return UserResource::collection($users);
    }

    public function auditLogs(Request $request): AnonymousResourceCollection
    {
        $logs = AuditLog::withoutGlobalScope(CompanyScope::class)
            ->with('user')
            ->when($request->query('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->latest('id')
            ->paginate($this->perPage($request));

        return AuditLogResource::collection($logs);
    }
}
