<?php

namespace App\Domain\Authentication\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Queued version of Laravel's verification e-mail. Link format: see AppServiceProvider. */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
