<?php

namespace App\Domain\Companies\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // Only scalars are stored so the queued job never has to re-query
    // tenant-scoped models outside a tenant context.
    public function __construct(
        public string $companyName,
        public string $inviterName,
        public string $acceptUrl,
        public int $validDays,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->inviterName} invited you to {$this->companyName}")
            ->line("{$this->inviterName} has invited you to join {$this->companyName}.")
            ->action('Accept invitation', $this->acceptUrl)
            ->line("This invitation expires in {$this->validDays} days.");
    }
}
