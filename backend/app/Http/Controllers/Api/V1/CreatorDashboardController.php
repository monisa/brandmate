<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Companies\CompanyScope;
use App\Domain\Influencers\Models\ProfileView;
use App\Domain\Requests\Models\CollaborationRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a creator sees first: how many brands looked at their profile, what needs an answer,
 * and what to do next. Everything is read through the signed-in creator's own profile.
 */
class CreatorDashboardController extends Controller
{
    private const DAYS = 14;

    public function show(Request $request): JsonResponse
    {
        $creator = $request->user()->creatorProfile->loadCount('packages');

        $requests = CollaborationRequest::withoutGlobalScope(CompanyScope::class)->where('influencer_id', $creator->id);
        $byStatus = (clone $requests)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $pending = (int) ($byStatus[CollaborationRequest::PENDING] ?? 0);

        $views = ProfileView::where('influencer_id', $creator->id)->where('viewed_at', '>=', now()->subDays(30)->startOfDay())->with('company')->get();
        $week = $views->where('viewed_at', '>=', now()->subDays(7)->startOfDay());

        // One bar per day for the last two weeks, including days with no visits.
        $perDay = $views->groupBy(fn (ProfileView $v) => $v->viewed_at->toDateString())->map->count();
        $series = collect(range(self::DAYS - 1, 0))->map(fn (int $ago) => [
            'date' => ($day = now()->subDays($ago)->toDateString()),
            'count' => (int) ($perDay[$day] ?? 0),
        ])->values();

        $missing = $creator->missingForListing();

        return response()->json(['data' => [
            'approval_status' => $creator->approval_status,
            'review_note' => $creator->review_note,
            'listed' => $creator->isListed(),
            'visible_to_brands' => $creator->isVisibleToBrands(),
            'views' => [
                'last_7_days' => $week->count(),
                'last_30_days' => $views->count(),
                'brands_30_days' => $views->pluck('company_id')->unique()->count(),
                'series' => $series,
            ],
            // The most recent visit from each company, newest first.
            'recent_visitors' => $views->sortByDesc('viewed_at')->unique('company_id')->take(5)->map(fn (ProfileView $v) => [
                'company' => $v->company?->name,
                'at' => $v->viewed_at->toIso8601String(),
            ])->values(),
            'requests' => [
                'pending' => $pending,
                'accepted' => (int) ($byStatus[CollaborationRequest::ACCEPTED] ?? 0),
                'declined' => (int) ($byStatus[CollaborationRequest::DECLINED] ?? 0),
            ],
            'actions' => $this->actions($creator, $pending, $missing),
        ]]);
    }

    /**
     * What this creator should do next, most important first. The app words each item;
     * the API only says what kind it is, so the wording can be translated.
     *
     * @param  list<string>  $missing
     * @return list<array<string, mixed>>
     */
    private function actions($creator, int $pending, array $missing): array
    {
        $actions = [];

        if ($pending > 0) {
            $actions[] = ['type' => 'answer_requests', 'count' => $pending];
        }
        if ($creator->approval_status === ApprovalStatus::REJECTED) {
            $actions[] = ['type' => 'approval_rejected', 'note' => $creator->review_note];
        } elseif ($creator->approval_status === ApprovalStatus::PENDING) {
            $actions[] = ['type' => 'awaiting_approval'];
        }
        if ($missing) {
            $actions[] = ['type' => 'complete_profile', 'items' => $missing];
        } elseif (! $creator->isListed()) {
            $actions[] = ['type' => 'list_profile'];
        }
        if ($creator->packages_count === 0) {
            $actions[] = ['type' => 'add_package'];
        }
        if (! $creator->bio) {
            $actions[] = ['type' => 'add_bio'];
        }

        return $actions;
    }
}
