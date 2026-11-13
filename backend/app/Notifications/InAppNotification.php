<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A message shown inside the app. It stores a machine-readable kind and a few values, not
 * finished text, so the app can show it in the reader's language.
 *
 * Kinds: request.received, request.answered, request.withdrawn,
 *        creator.approved, creator.rejected, company.approved, company.rejected.
 */
class InAppNotification extends Notification
{
    /** @param array<string, scalar|null> $meta */
    public function __construct(
        private readonly string $kind,
        private readonly array $meta = [],
        private readonly ?string $link = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'meta' => $this->meta, 'link' => $this->link];
    }
}
