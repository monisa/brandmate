<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Companies\Models\Company;
use App\Domain\Subscriptions\Entitlements;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

/**
 * The brands a creator can browse, mirroring what a brand sees when it browses creators:
 * cards that anyone can scroll, with phone, e-mail, channel name and website hidden, and a full
 * profile that opens only for a subscriber. Only approved, active companies are listed.
 */
class BrandDirectoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'industry' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'size:2'],
            'sort' => ['nullable', Rule::in(['name', 'newest'])],
        ]);
        $unlocked = Entitlements::allowsRequest($request);

        $companies = $this->listed()
            ->with('owner')
            ->when(filled($filters['q'] ?? null), function ($q) use ($filters) {
                // Escape LIKE wildcards so "100%" searches for the literal text.
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])).'%';

                return $q->where('name', 'like', $term);
            })
            ->when($filters['industry'] ?? null, fn ($q, $v) => $q->where('industry', $v))
            ->when($filters['country'] ?? null, fn ($q, $v) => $q->where('country', strtoupper($v)))
            ->when(($filters['sort'] ?? 'name') === 'newest', fn ($q) => $q->latest('id'), fn ($q) => $q->orderBy('name'))
            ->paginate($this->perPage($request));

        return JsonResource::collection($companies->through(fn (Company $c) => $this->card($c, $unlocked)));
    }

    /** Values for the filter dropdowns, taken from the brands that are listed. */
    public function filters(): JsonResponse
    {
        return response()->json(['data' => [
            'industries' => $this->listed()->whereNotNull('industry')->distinct()->orderBy('industry')->pluck('industry'),
            'countries' => $this->listed()->whereNotNull('country')->distinct()->orderBy('country')->pluck('country'),
        ]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $company = $this->listed()->with('owner')->findOrFail($id);

        if (! Entitlements::allowsRequest($request)) {
            abort(response()->json([
                'message' => 'Subscribe to open full brand profiles and see contact details.',
                'code' => 'subscription_required',
            ], 403));
        }

        return response()->json(['data' => $this->card($company, true)]);
    }

    private function listed()
    {
        return Company::where('approval_status', ApprovalStatus::APPROVED)->where('status', 'active');
    }

    /** @return array<string, mixed> */
    private function card(Company $company, bool $unlocked): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'industry' => $company->industry,
            'country' => $company->country,
            'locked' => ! $unlocked,
            'contact' => [
                'email' => Entitlements::reveal($company->owner?->email, $unlocked),
                'phone' => Entitlements::reveal($company->phone, $unlocked),
                'channel_name' => Entitlements::reveal($company->channel_name, $unlocked),
                'website' => Entitlements::reveal($company->website, $unlocked),
            ],
        ];
    }
}
