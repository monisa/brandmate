<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Companies\TenantContext;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Requests\Models\CollaborationRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A brand asking creators to work together. Everything here is tenant-scoped: a company
 * only ever sees and changes its own requests.
 */
class RequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(CollaborationRequest::STATUSES)]]);

        $requests = CollaborationRequest::with(['influencer.profiles', 'influencer.user', 'requester', 'package'])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate($this->perPage($request));

        return JsonResource::collection($requests->through(fn (CollaborationRequest $r) => $r->toApi('brand')));
    }

    public function store(Request $request, TenantContext $tenant, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'creator_id' => ['required', 'integer'],
            'package_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:2000'],
            'budget' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        // Only creators brands can see (listed and approved) can be asked.
        $creator = Influencer::visibleToBrands()->findOrFail($data['creator_id']);

        $package = null;
        if (! empty($data['package_id'])) {
            $package = $creator->packages()->where('is_active', true)->find($data['package_id']);
            if (! $package) {
                throw ValidationException::withMessages(['package_id' => ['That package is not available.']]);
            }
        }

        $created = CollaborationRequest::create([
            'company_id' => $tenant->id(),
            'influencer_id' => $creator->id,
            'requested_by' => $request->user()->id,
            'package_id' => $package?->id,
            'title' => $data['title'],
            'message' => $data['message'],
            'budget_minor' => isset($data['budget']) ? (int) $data['budget'] * 100 : null,
            'currency' => config('platform.currency'),
            'due_date' => $data['due_date'] ?? null,
            'status' => CollaborationRequest::PENDING,
        ]);

        $audit->log('request.sent', $created, ['creator_id' => $creator->id]);

        return response()->json(['data' => $this->fresh($created)->toApi('brand')], 201);
    }

    /** Withdraws a request the creator has not answered yet. */
    public function withdraw(int $id, AuditLogger $audit): JsonResponse
    {
        $found = CollaborationRequest::findOrFail($id); // tenant-scoped: other companies' requests are a 404

        if ($found->status !== CollaborationRequest::PENDING) {
            throw ValidationException::withMessages(['status' => ['Only a request that has not been answered can be withdrawn.']]);
        }

        $found->update(['status' => CollaborationRequest::WITHDRAWN]);
        $audit->log('request.withdrawn', $found);

        return response()->json(['data' => $this->fresh($found)->toApi('brand')]);
    }

    private function fresh(CollaborationRequest $request): CollaborationRequest
    {
        return $request->load(['influencer.profiles', 'influencer.user', 'requester', 'package']);
    }
}
