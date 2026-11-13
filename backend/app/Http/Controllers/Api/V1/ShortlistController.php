<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shortlists\Models\Shortlist;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShortlistResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Shortlists belong to a company and are visible to everyone in it.
 * Shortlist is tenant-scoped, so every lookup below is already limited
 * to the caller's company; another company's id simply returns 404.
 */
class ShortlistController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ShortlistResource::collection(
            Shortlist::withCount('items')->with('creator')->latest('updated_at')->paginate($this->perPage($request, 50))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $shortlist = Shortlist::create([
            'name' => $this->validatedName($request),
            'created_by' => $request->user()->id,
        ]);

        return (new ShortlistResource($shortlist->loadCount('items')->load('creator')))->response()->setStatusCode(201);
    }

    public function show(int $id): ShortlistResource
    {
        return new ShortlistResource($this->detail($id));
    }

    public function update(Request $request, int $id): ShortlistResource
    {
        $shortlist = Shortlist::findOrFail($id);
        $shortlist->update(['name' => $this->validatedName($request, $shortlist->id)]);

        return new ShortlistResource($shortlist->loadCount('items')->load('creator'));
    }

    public function destroy(int $id): JsonResponse
    {
        Shortlist::findOrFail($id)->delete();

        return response()->json(['message' => 'Shortlist deleted.']);
    }

    /** Adds one or more influencers; ones already in the list are left as they are. */
    public function addInfluencers(Request $request, int $id): JsonResponse
    {
        $shortlist = Shortlist::findOrFail($id);

        $data = $request->validate([
            'influencer_ids' => ['required', 'array', 'min:1', 'max:100'],
            'influencer_ids.*' => ['integer', 'distinct', Rule::exists('social_profiles', 'id')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $existing = $shortlist->items()->pluck('social_profile_id')->all();
        $new = array_values(array_diff(array_map('intval', $data['influencer_ids']), $existing));

        foreach ($new as $profileId) {
            $shortlist->items()->create([
                'social_profile_id' => $profileId,
                'notes' => $data['notes'] ?? null,
                'added_by' => $request->user()->id,
            ]);
        }

        if ($new) {
            $shortlist->touch();
        }

        return response()->json(['data' => [
            'added' => count($new),
            'already_in_list' => count($data['influencer_ids']) - count($new),
        ]]);
    }

    public function updateInfluencer(Request $request, int $id, int $influencerId): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $item = Shortlist::findOrFail($id)->items()->where('social_profile_id', $influencerId)->firstOrFail();
        $item->update(['notes' => $data['notes'] ?? null]);

        return response()->json(['data' => ['notes' => $item->notes]]);
    }

    public function removeInfluencer(int $id, int $influencerId): JsonResponse
    {
        $shortlist = Shortlist::findOrFail($id);
        $shortlist->items()->where('social_profile_id', $influencerId)->firstOrFail()->delete();
        $shortlist->touch();

        return response()->json(['message' => 'Removed from shortlist.']);
    }

    private function detail(int $id): Shortlist
    {
        return Shortlist::with(['creator', 'items' => fn ($q) => $q->latest('id'), 'items.profile.influencer'])
            ->withCount('items')
            ->findOrFail($id);
    }

    private function validatedName(Request $request, ?int $ignoreId = null): string
    {
        $companyId = $request->user()->membership->company_id;

        return $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('shortlists', 'name')->where('company_id', $companyId)->ignore($ignoreId),
            ],
        ], ['name.unique' => 'Your company already has a shortlist with this name.'])['name'];
    }
}
