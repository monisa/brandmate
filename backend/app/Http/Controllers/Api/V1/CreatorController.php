<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Influencers\Models\SocialProfile;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A creator managing their own profile, social accounts and packages.
 * Everything is reached through $request->user()->creatorProfile, so one
 * creator can never touch another creator's records.
 */
class CreatorController extends Controller
{
    /** Hosts a profile link may point to, per platform. */
    private const HOSTS = [
        'instagram' => ['instagram.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'facebook' => ['facebook.com', 'fb.com'],
    ];

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->creator($request));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['nullable', Rule::in(config('platform.categories'))],
            'bio' => ['nullable', 'string', 'max:1000'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'state' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'language' => ['nullable', 'string', 'max:60'],
        ]);

        if (isset($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }

        $creator = $this->creator($request);
        $creator->update($data);
        $this->hideIfIncomplete($creator);

        return $this->respond($creator);
    }

    /** Turns visibility to brands on or off. */
    public function setListing(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['listed' => ['required', 'boolean']]);
        $creator = $this->creator($request);

        if ($data['listed'] && ($missing = $creator->missingForListing())) {
            throw ValidationException::withMessages(['listed' => $missing]);
        }

        $creator->update(['status' => $data['listed'] ? Influencer::LISTED : Influencer::HIDDEN]);
        $audit->log($data['listed'] ? 'creator.listed' : 'creator.hidden', $creator);

        return $this->respond($creator);
    }

    // ---- Social accounts ----

    public function storeAccount(Request $request): JsonResponse
    {
        $creator = $this->creator($request);
        $data = $this->validateAccount($request);

        $creator->profiles()->create($data + [
            // Numbers typed in by the creator. They stay marked as unverified
            // until the account is connected through the platform's own API.
            'data_source' => 'self_reported',
            'provider' => 'Entered by the creator',
            'sync_status' => 'unverified',
            'last_synced_at' => now(),
        ]);

        return $this->respond($creator, 201);
    }

    public function updateAccount(Request $request, int $id): JsonResponse
    {
        $creator = $this->creator($request);
        $account = $creator->profiles()->findOrFail($id);

        $account->update($this->validateAccount($request, $account) + ['last_synced_at' => now()]);

        return $this->respond($creator);
    }

    public function destroyAccount(Request $request, int $id): JsonResponse
    {
        $creator = $this->creator($request);
        $creator->profiles()->findOrFail($id)->delete();
        $this->hideIfIncomplete($creator);

        return $this->respond($creator);
    }

    // ---- Packages ----

    public function storePackage(Request $request): JsonResponse
    {
        $creator = $this->creator($request);
        $creator->packages()->create($this->validatePackage($request) + ['currency' => config('platform.currency')]);

        return $this->respond($creator, 201);
    }

    public function updatePackage(Request $request, int $id): JsonResponse
    {
        $creator = $this->creator($request);
        $creator->packages()->findOrFail($id)->update($this->validatePackage($request));

        return $this->respond($creator);
    }

    public function destroyPackage(Request $request, int $id): JsonResponse
    {
        $creator = $this->creator($request);
        $creator->packages()->findOrFail($id)->delete();

        return $this->respond($creator);
    }

    // ---- Helpers ----

    private function creator(Request $request): Influencer
    {
        return $request->user()->creatorProfile;
    }

    /** A listed profile that loses a required part goes back to hidden. */
    private function hideIfIncomplete(Influencer $creator): void
    {
        if ($creator->isListed() && $creator->missingForListing()) {
            $creator->update(['status' => Influencer::HIDDEN]);
        }
    }

    private function validateAccount(Request $request, ?SocialProfile $existing = null): array
    {
        $request->merge(['username' => ltrim(trim((string) $request->input('username')), '@')]);

        $data = $request->validate([
            'platform' => ['required', Rule::in(SocialProfile::PLATFORMS)],
            'username' => [
                'required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-]+$/',
                Rule::unique('social_profiles', 'username')
                    ->where('platform', $request->input('platform'))
                    ->ignore($existing?->id),
            ],
            'profile_url' => ['required', 'url:https', 'max:255'],
            'followers' => ['required', 'integer', 'min:0', 'max:2000000000'],
        ], [
            'username.regex' => 'Use only letters, numbers, dots, dashes and underscores.',
            'username.unique' => 'This account is already registered on the platform.',
        ]);

        $host = strtolower((string) parse_url($data['profile_url'], PHP_URL_HOST));
        $host = preg_replace('/^(www|m)\./', '', $host);

        if (! in_array($host, self::HOSTS[$data['platform']], true)) {
            throw ValidationException::withMessages([
                'profile_url' => ['The link must be on '.self::HOSTS[$data['platform']][0].'.'],
            ]);
        }

        return $data;
    }

    private function validatePackage(Request $request): array
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(SocialProfile::PLATFORMS)],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'integer', 'min:1', 'max:100000000'], // whole currency units
            'delivery_days' => ['required', 'integer', 'min:1', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['price_minor'] = $data['price'] * 100;
        unset($data['price']);

        return $data;
    }

    private function respond(Influencer $creator, int $status = 200): JsonResponse
    {
        $creator->refresh()->load(['profiles', 'packages']);

        return response()->json(['data' => [
            'id' => $creator->id,
            'name' => $creator->name,
            'category' => $creator->category,
            'bio' => $creator->bio,
            'country' => $creator->country,
            'state' => $creator->state,
            'city' => $creator->city,
            'language' => $creator->language,
            'listed' => $creator->isListed(),
            'missing_for_listing' => $creator->missingForListing(),
            'category_options' => config('platform.categories'),
            'currency' => config('platform.currency'),
            'accounts' => $creator->profiles->sortBy('id')->values()->map(fn (SocialProfile $p) => [
                'id' => $p->id,
                'platform' => $p->platform,
                'username' => $p->username,
                'profile_url' => $p->profile_url,
                'followers' => $p->followers,
                'verified_data' => $p->data_source !== 'self_reported',
            ]),
            'packages' => $creator->packages->sortBy('id')->values()->map(fn ($package) => $package->toApi()),
        ]], $status);
    }
}
