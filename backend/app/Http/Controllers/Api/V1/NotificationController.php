<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Subscriptions\Entitlements;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** The signed-in person's in-app notifications. Always scoped to the caller. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $unlocked = Entitlements::allows($user);

        return response()->json([
            'data' => $user->notifications()->latest()->limit(30)->get()->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'kind' => $n->data['kind'] ?? 'unknown',
                'meta' => $this->meta($n, $unlocked),
                'link' => $n->data['link'] ?? null,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at->toIso8601String(),
            ])->values(),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /** A request's sender and title are part of what a subscription unlocks. */
    private function meta(DatabaseNotification $n, bool $unlocked): array
    {
        $meta = $n->data['meta'] ?? [];
        if (! $unlocked && ($n->data['kind'] ?? '') === 'request.received') {
            $meta['company'] = Entitlements::mask();
            $meta['title'] = Entitlements::mask();
        }

        return $meta;
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->findOrFail($id)->markAsRead(); // 404 for anyone else's

        return response()->json(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread_count' => 0]);
    }
}
