<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use App\Domain\Search\InfluencerSearch;
use App\Http\Controllers\Controller;
use App\Http\Resources\InfluencerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class InfluencerController extends Controller
{
    public const COMPARE_LIMIT = 5;

    public function index(Request $request, InfluencerSearch $search): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'platform' => ['nullable', Rule::in(SocialProfile::PLATFORMS)],
            'category' => ['nullable', 'string', 'max:60'],
            'country' => ['nullable', 'string', 'size:2'],
            'followers_min' => ['nullable', 'integer', 'min:0'],
            'followers_max' => ['nullable', 'integer', 'min:0'],
            'engagement_min' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'engagement_max' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'verified' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(InfluencerSearch::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        // Phase 3 hooks the plan's search limit in here (usage_records).

        return InfluencerResource::collection($search->search($filters, $this->perPage($request)));
    }

    /** Values for the filter dropdowns, taken from the data that exists. */
    public function filters(): JsonResponse
    {
        return response()->json(['data' => [
            'platforms' => SocialProfile::PLATFORMS,
            'categories' => Influencer::where('status', Influencer::LISTED)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'countries' => Influencer::where('status', Influencer::LISTED)->whereNotNull('country')->distinct()->orderBy('country')->pluck('country'),
        ]]);
    }

    public function show(int $id): InfluencerResource
    {
        $profile = SocialProfile::with(['influencer.profiles', 'influencer.packages'])->findOrFail($id);

        abort_unless($profile->influencer->isListed(), 404); // hidden by the creator

        return new InfluencerResource($profile);
    }

    public function compare(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::COMPARE_LIMIT],
            'ids.*' => ['integer', 'distinct'],
        ], [
            'ids.max' => 'You can compare up to '.self::COMPARE_LIMIT.' influencers at a time.',
        ]);

        $ids = array_map('intval', $data['ids']);
        $profiles = SocialProfile::with('influencer')->whereIn('id', $ids)
            ->whereHas('influencer', fn ($q) => $q->where('status', Influencer::LISTED))
            ->get()
            ->sortBy(fn ($p) => array_search($p->id, $ids, true)) // keep the order that was asked for
            ->values();

        return InfluencerResource::collection($profiles);
    }
}
