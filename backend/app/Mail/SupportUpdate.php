<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells the support team about a new ticket or reply, and tells a person that support answered. */
class SupportUpdate extends Mailable
{
    use Queueable;

    public const TO_SUPPORT = 'to_support';

    public const TO_USER = 'to_user';

    public function __construct(
        public readonly string $direction,
        public readonly int $ticketId,
        public readonly string $ticketSubject,
        public readonly string $from,
        public readonly string $body,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->direction === self::TO_SUPPORT
            ? "[Ticket #{$this->ticketId}] {$this->ticketSubject}"
            : "BrandMate support replied to your ticket #{$this->ticketId}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.support-update');
    }
}
