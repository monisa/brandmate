<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Companies\CompanyScope;
use App\Domain\Requests\Models\CollaborationRequest;
use App\Http\Controllers\Controller;
use App\Notifications\InAppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A creator's inbox of requests from brands. Reached only through the signed-in
 * creator's own profile, so one creator can never read another's requests.
 */
class CreatorRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(CollaborationRequest::STATUSES)]]);
        $creatorId = $request->user()->creatorProfile->id;

        $requests = $this->inbox($creatorId)
            ->with(['company', 'requester', 'package'])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate($this->perPage($request));

        $unlocked = Entitlements::allowsRequest($request);

        return JsonResource::collection($requests->through(fn (CollaborationRequest $r) => $r->toApi('creator', $unlocked)))
            ->additional(['pending_count' => $this->inbox($creatorId)->where('status', CollaborationRequest::PENDING)->count()]);
    }

    /** Accepts or declines a request, with an optional note back to the brand. */
    public function respond(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([CollaborationRequest::ACCEPTED, CollaborationRequest::DECLINED])],
            'response' => ['nullable', 'string', 'max:1000'],
        ]);

        $found = $this->inbox($request->user()->creatorProfile->id)->with(['company', 'requester', 'package'])->findOrFail($id);

        if ($found->status !== CollaborationRequest::PENDING) {
            throw ValidationException::withMessages(['decision' => ['This request has already been answered or was withdrawn.']]);
        }

        $found->forceFill([
            'status' => $data['decision'],
            'creator_response' => $data['response'] ?? null,
            'responded_at' => now(),
        ])->save();

        $audit->log('request.'.$data['decision'], $found, companyId: $found->company_id);
        $found->requester?->notify(new InAppNotification('request.answered', [
            'creator' => $request->user()->name, 'title' => $found->title, 'decision' => $data['decision'],
        ], '/requests'));

        return response()->json(['data' => $found->toApi('creator', Entitlements::allowsRequest($request))]);
    }

    /** Requests are tenant-scoped on the brand side; the creator reads across companies by their own id. */
    private function inbox(int $creatorId)
    {
        return CollaborationRequest::withoutGlobalScope(CompanyScope::class)->where('influencer_id', $creatorId);
    }
}
