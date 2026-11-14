<?php

namespace App\Domain\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/** Sends a Web Push message to every browser a person has signed up. Never throws: a failed push must not fail a chat message. */
class PushSender
{
    public static function configured(): bool
    {
        return filled(config('push.public_key')) && filled(config('push.private_key'));
    }

    /** @param  iterable<int, int>  $userIds */
    public static function notify(iterable $userIds, string $title, string $body, string $url): void
    {
        if (! self::configured()) {
            return;
        }

        try {
            $subscriptions = PushSubscription::whereIn('user_id', collect($userIds)->unique()->values()->all())->get();
            if ($subscriptions->isEmpty()) {
                return;
            }

            $webPush = new WebPush(['VAPID' => [
                'subject' => config('push.subject'),
                'publicKey' => config('push.public_key'),
                'privateKey' => config('push.private_key'),
            ]], [], 8);

            // The Angular service worker reads this shape and shows the notification itself.
            $payload = json_encode(['notification' => [
                'title' => $title,
                'body' => $body,
                'icon' => '/icon-192.png',
                'badge' => '/icon-192.png',
                'tag' => 'brandmate-chat',
                'renotify' => true,
                'vibrate' => [200, 100, 200],
                'data' => ['onActionClick' => ['default' => ['operation' => 'navigateLastFocusedOrOpen', 'url' => $url]]],
            ]], JSON_UNESCAPED_UNICODE);

            foreach ($subscriptions as $row) {
                $webPush->queueNotification(
                    Subscription::create(['endpoint' => $row->endpoint, 'publicKey' => $row->public_key, 'authToken' => $row->auth_token]),
                    $payload,
                    ['TTL' => 3600],
                );
            }

            foreach ($webPush->flush() as $report) {
                // The browser says the subscription is gone (uninstalled, permission removed): forget it.
                if ($report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
