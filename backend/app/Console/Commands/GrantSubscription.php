<?php

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Subscriptions\Entitlements;
use App\Domain\Subscriptions\Models\Subscription;
use App\Domain\Subscriptions\SubscriptionPricing;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Gives a creator, or a brand company through any of its members, access for a number of days
 * without a payment (for demos and support), or takes it away again. A creator is covered
 * personally; a brand member covers the whole company.
 *
 *   php artisan subscription:grant demo@example.com --days=30
 *   php artisan subscription:grant demo@example.com --revoke
 */
class GrantSubscription extends Command
{
    protected $signature = 'subscription:grant {email : A creator, or any member of a brand company} {--days=30} {--revoke}';

    protected $description = 'Grant or revoke subscription access by hand';

    public function handle(): int
    {
        $user = User::where('email', strtolower(trim((string) $this->argument('email'))))->first();
        if (! $user || $user->isSuperAdmin()) {
            $this->error('No creator or brand account has that e-mail.');

            return self::FAILURE;
        }

        if ($user->isCreator()) {
            $where = ['user_id' => $user->id];
        } elseif ($companyId = $user->membership?->company_id) {
            $where = ['company_id' => $companyId];
        } else {
            $this->error('That person does not belong to a company.');

            return self::FAILURE;
        }

        $subscription = Subscription::where($where)->first();

        if ($this->option('revoke')) {
            $subscription?->forceFill(['status' => Subscription::EXPIRED, 'current_period_end' => now(), 'cancel_at_period_end' => true])->save();
            $this->audit($user, 'subscription.revoked');
            $this->info('Access removed.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        Subscription::updateOrCreate($where, [
            'status' => Subscription::ACTIVE,
            'gateway' => $subscription?->gateway_subscription_id ? 'razorpay' : 'manual',
            'price_minor' => SubscriptionPricing::priceMinor(),
            'gst_minor' => SubscriptionPricing::gstMinor(),
            'total_minor' => SubscriptionPricing::totalMinor(),
            'currency' => config('subscription.currency'),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($days),
            'cancel_at_period_end' => true, // a grant does not renew by itself
        ]);
        $this->audit($user, 'subscription.granted', ['days' => $days]);

        $this->info("Access granted for {$days} days.".(Entitlements::enforced() ? '' : ' (Subscriptions are not enforced yet, so everyone already has access.)'));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $user, string $action, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $user->id, 'action' => $action, 'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id,
            'metadata' => $metadata ?: null, 'created_at' => now(),
        ]);
    }
}
