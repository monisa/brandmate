<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells a creator, or a brand's company admin, that the paid month is about to end or has ended. */
class SubscriptionReminder extends Mailable
{
    use Queueable;

    public const ENDING = 'ending';

    public const ENDED = 'ended';

    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly string $endsOn,
        public readonly string $payUrl,
        public readonly bool $forCompany,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind === self::ENDING
            ? 'Your BrandMate month ends on '.$this->endsOn
            : 'Your BrandMate subscription has ended');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.subscription-reminder');
    }
}
