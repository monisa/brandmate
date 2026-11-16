<?php

namespace App\Console\Commands;

use App\Domain\Notifications\WhatsApp;
use App\Domain\Rbac\Models\Role;
use App\Domain\Subscriptions\Entitlements;
use App\Mail\SubscribeNudge;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells people who signed up but never subscribed that one payment unlocks the site: an e-mail,
 * plus a WhatsApp message when they gave a phone number and WhatsApp is set up. Each account is
 * told once. Safe to run as often as you like.
 *
 *   php artisan subscriptions:nudge
 *   php artisan subscriptions:nudge --dry-run
 */
class SendSubscribeNudges extends Command
{
    protected $signature = 'subscriptions:nudge {--hours=24 : How long after signing up to wait} {--dry-run : Only report who would be contacted}';

    protected $description = 'E-mail and WhatsApp people who signed up but have not subscribed';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = ['emailed' => 0, 'whatsapp' => 0, 'failed' => 0];

        // While access is free for everyone there is nothing to unlock, so nobody is nagged.
        if (Entitlements::enforced()) {
            $users = User::query()
                ->where('is_super_admin', false)
                ->whereNull('deactivated_at')
                ->whereNull('signup_nudged_at')
                ->whereNotNull('email_verified_at')
                ->where('created_at', '<=', now()->subHours(max(0, (int) $this->option('hours'))))
                ->where('created_at', '>=', now()->subDays(30)) // never wake up long-forgotten accounts
                ->get();

            foreach ($users as $user) {
                if (! $this->shouldBeTold($user)) {
                    continue;
                }

                if ($dry) {
                    $this->line("nudge: {$user->email}".($user->phone ? " / {$user->phone}" : ''));
                    $counts['emailed']++;

                    continue;
                }

                try {
                    Mail::to($user->email)->send(new SubscribeNudge(
                        $user->name,
                        rtrim((string) config('platform.frontend_url'), '/').'/billing',
                        ! $user->isCreator(),
                    ));
                    $counts['emailed']++;
                    $user->forceFill(['signup_nudged_at' => now()])->save();
                } catch (Throwable $e) {
                    report($e);
                    $counts['failed']++;

                    continue; // try again next run
                }

                if ($user->phone && WhatsApp::enabled()) {
                    try {
                        WhatsApp::sendTemplate($user->phone, explode(' ', trim($user->name))[0]) ? $counts['whatsapp']++ : $counts['failed']++;
                    } catch (Throwable $e) {
                        report($e);
                        $counts['failed']++;
                    }
                }
            }
        }

        Log::build(['driver' => 'single', 'path' => storage_path('logs/reminders.log'), 'level' => 'info'])
            ->info('subscriptions:nudge ran', $counts + ['dry_run' => $dry]);

        $this->info(($dry ? 'Dry run: ' : '')."e-mailed {$counts['emailed']}, WhatsApp {$counts['whatsapp']}, failed {$counts['failed']}");

        return self::SUCCESS;
    }

    /** A creator for themselves; for a brand only the company admin, who is the one who pays for the team. */
    private function shouldBeTold(User $user): bool
    {
        if (! $user->isCreator() && $user->membership?->role?->slug !== Role::COMPANY_ADMIN) {
            return false;
        }

        return ! (Entitlements::subscriptionOf($user)?->grantsAccess() ?? false);
    }
}
