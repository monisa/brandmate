<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent once to someone who signed up but has not paid yet. */
class SubscribeNudge extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $payUrl,
        public readonly bool $forCompany,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your BrandMate account is ready: unlock contacts and chat');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.subscribe-nudge');
    }
}
