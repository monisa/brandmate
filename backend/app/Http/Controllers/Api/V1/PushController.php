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
            'endpoint' => ['required', 'max:2000', $this->pushServiceUrl()],
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

    /**
     * The server posts to whatever address is registered here, so only the browsers' own push
     * services are accepted. Anything else (an internal address, a link-local metadata service)
     * would let a signed-in person make this server send requests wherever they like.
     */
    private function pushServiceUrl(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $parts = is_string($value) ? parse_url($value) : false;
            $host = strtolower((string) ($parts['host'] ?? ''));
            $allowed = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'];
            $suffixes = ['.push.services.mozilla.com', '.notify.windows.com', '.push.apple.com'];

            $ok = $parts !== false
                && ($parts['scheme'] ?? '') === 'https'
                && ! isset($parts['user'], $parts['pass'])
                && (! isset($parts['port']) || $parts['port'] === 443)
                && ($host !== '' && (in_array($host, $allowed, true) || collect($suffixes)->contains(fn ($s) => str_ends_with($host, $s))));

            if (! $ok) {
                $fail('This is not a browser push service address.');
            }
        };
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->json(['status' => 'unsubscribed']);
    }
}
