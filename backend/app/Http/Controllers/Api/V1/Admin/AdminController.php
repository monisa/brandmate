<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Companies\CompanyScope;
use App\Domain\Companies\Models\Company;
use App\Domain\Influencers\Models\Influencer;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
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
            // Waiting for a decision from the platform team.
            'pending_companies' => Company::where('approval_status', ApprovalStatus::PENDING)->count(),
            'pending_creators' => $this->creators()->where('approval_status', ApprovalStatus::PENDING)->count(),
        ]]);
    }

    public function companies(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['approval' => ['nullable', Rule::in(ApprovalStatus::ALL)]]);

        $companies = Company::withCount('memberships')
            ->with('owner')
            ->when($request->query('search'), fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->when($data['approval'] ?? null, fn ($q, $status) => $q->where('approval_status', $status))
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

    /** Approves or rejects a brand. It can be changed later, for example to revoke an approval. */
    public function reviewCompany(Request $request, int $id, AuditLogger $audit): CompanyResource
    {
        $data = $this->validateReview($request);

        $company = Company::findOrFail($id);
        $this->applyReview($company, $data, $request);
        $audit->log('admin.company_reviewed', $company, $data, companyId: $company->id);

        return new CompanyResource($company->loadCount('memberships')->load('owner'));
    }

    /** Creators who signed up themselves (sample and imported profiles have no login and need no review). */
    public function creatorList(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['approval' => ['nullable', Rule::in(ApprovalStatus::ALL)]]);

        $creators = $this->creators()
            ->with(['user', 'profiles'])
            ->when($request->query('search'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', '%'.$term.'%')
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', '%'.$term.'%'))
            ))
            ->when($data['approval'] ?? null, fn ($q, $status) => $q->where('approval_status', $status))
            ->latest('id')
            ->paginate($this->perPage($request));

        return JsonResource::collection($creators->through(fn (Influencer $c) => $this->creatorArray($c)));
    }

    public function reviewCreator(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $data = $this->validateReview($request);

        $creator = $this->creators()->with(['user', 'profiles'])->findOrFail($id);
        $this->applyReview($creator, $data, $request);
        $audit->log('admin.creator_reviewed', $creator, $data);

        return response()->json(['data' => $this->creatorArray($creator)]);
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

    private function creators()
    {
        return Influencer::whereNotNull('user_id');
    }

    /** @return array{decision: string, note?: ?string} */
    private function validateReview(Request $request): array
    {
        return $request->validate([
            'decision' => ['required', Rule::in([ApprovalStatus::APPROVED, ApprovalStatus::REJECTED])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function applyReview(Company|Influencer $subject, array $data, Request $request): void
    {
        // Not mass-assignable on purpose: only this reviewed path may set them.
        $subject->forceFill([
            'approval_status' => $data['decision'],
            'review_note' => $data['note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();
    }

    /** @return array<string, mixed> */
    private function creatorArray(Influencer $creator): array
    {
        return [
            'id' => $creator->id,
            'name' => $creator->name,
            'email' => $creator->user?->email,
            'category' => $creator->category,
            'country' => $creator->country,
            'listed' => $creator->isListed(),
            'approval_status' => $creator->approval_status,
            'review_note' => $creator->review_note,
            'reviewed_at' => $creator->reviewed_at?->toIso8601String(),
            'created_at' => $creator->created_at?->toIso8601String(),
            'accounts' => $creator->profiles->sortBy('id')->values()->map(fn ($p) => [
                'platform' => $p->platform,
                'username' => $p->username,
                'followers' => $p->followers,
            ]),
        ];
    }
}
