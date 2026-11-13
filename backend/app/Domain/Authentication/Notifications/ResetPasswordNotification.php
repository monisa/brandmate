<?php

namespace App\Domain\Authentication\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Queued version of Laravel's password reset e-mail. Link format: see AppServiceProvider. */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
