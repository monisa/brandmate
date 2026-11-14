<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Push\PushSender;
use App\Domain\Push\PushSubscription;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    /** The public key the browser needs to subscribe; null when push is not set up on this server. */
    public function key(): JsonResponse
    {
        return response()->json(['public_key' => PushSender::configured() ? config('push.public_key') : null]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        // The same browser can be re-registered by another person who signs in on it: it belongs to whoever registered last.
        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->json(['status' => 'subscribed'], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'url', 'max:2000']]);

        PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->json(['status' => 'unsubscribed']);
    }
}
