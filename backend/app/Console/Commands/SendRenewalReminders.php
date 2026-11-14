<?php

namespace App\Console\Commands;

use App\Domain\Rbac\Models\Role;
use App\Domain\Subscriptions\Models\Subscription;
use App\Mail\SubscriptionReminder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mails people whose paid month is about to end, and again when it has ended. Safe to run as
 * often as you like (every hour is fine): each e-mail goes out once per month paid for.
 *
 *   php artisan subscriptions:remind
 *   php artisan subscriptions:remind --dry-run
 */
class SendRenewalReminders extends Command
{
    protected $signature = 'subscriptions:remind {--days=3 : How many days before the end to warn} {--dry-run : Only report who would be e-mailed}';

    protected $description = 'E-mail renewal reminders for subscriptions that are ending or have ended';

    public function handle(): int
    {
        $warnDays = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $counts = ['ending' => 0, 'ended' => 0, 'failed' => 0];

        // Only subscriptions that were ever paid for. A month ended long ago is not worth an e-mail.
        $base = Subscription::query()
            ->whereIn('status', [Subscription::ACTIVE, Subscription::CANCELLED, Subscription::PAST_DUE, Subscription::EXPIRED])
            ->whereNotNull('current_period_end');

        $ending = (clone $base)
            ->whereBetween('current_period_end', [now(), now()->addDays($warnDays)])
            ->where(fn ($q) => $q->whereNull('ending_reminded_for')->orWhereColumn('ending_reminded_for', '!=', 'current_period_end'))
            ->get();

        $ended = (clone $base)
            ->whereBetween('current_period_end', [now()->subDays(14), now()])
            ->where(fn ($q) => $q->whereNull('ended_notified_for')->orWhereColumn('ended_notified_for', '!=', 'current_period_end'))
            ->get();

        foreach ([[SubscriptionReminder::ENDING, $ending, 'ending_reminded_for'], [SubscriptionReminder::ENDED, $ended, 'ended_notified_for']] as [$kind, $rows, $column]) {
            foreach ($rows as $subscription) {
                $recipients = $this->recipients($subscription);
                $sent = false;

                foreach ($recipients as $user) {
                    if ($dry) {
                        $this->line("{$kind}: {$user->email} (period ends {$subscription->current_period_end->toDateString()})");
                        $sent = true;

                        continue;
                    }

                    try {
                        Mail::to($user->email)->send(new SubscriptionReminder(
                            $kind,
                            $user->name,
                            $subscription->current_period_end->timezone(config('app.timezone'))->format('j F Y'),
                            rtrim((string) config('platform.frontend_url'), '/').'/billing',
                            $subscription->company_id !== null,
                        ));
                        $sent = true;
                    } catch (Throwable $e) {
                        report($e);
                        $counts['failed']++;
                    }
                }

                // Only mark it done when at least one e-mail went out, so a mail outage is retried next run.
                if ($sent && ! $dry) {
                    $subscription->forceFill([$column => $subscription->current_period_end])->save();
                }
                if ($sent) {
                    $counts[$kind]++;
                }
            }
        }

        $this->info(($dry ? 'Dry run: ' : '')."ending soon {$counts['ending']}, ended {$counts['ended']}, failed {$counts['failed']}");

        return self::SUCCESS;
    }

    /** A creator hears about their own subscription; a brand's company admins hear about the company's. @return \Illuminate\Support\Collection<int, User> */
    private function recipients(Subscription $subscription)
    {
        if ($subscription->user_id !== null) {
            return User::whereKey($subscription->user_id)->whereNull('deactivated_at')->get();
        }

        $adminRole = Role::findSystem(Role::COMPANY_ADMIN);
        $ids = $subscription->company?->memberships()
            ->where('status', 'active')
            ->where('role_id', $adminRole->id)
            ->pluck('user_id') ?? collect();

        return User::whereIn('id', $ids)->whereNull('deactivated_at')->get();
    }
}
