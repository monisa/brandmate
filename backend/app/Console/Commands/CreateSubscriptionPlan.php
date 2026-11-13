<?php

namespace App\Console\Commands;

use App\Domain\Subscriptions\RazorpayClient;
use App\Domain\Subscriptions\SubscriptionPricing;
use Illuminate\Console\Command;
use Throwable;

/**
 * Creates the monthly plan in Razorpay once. Put the printed id in RAZORPAY_PLAN_ID.
 * The amount is Rs 999 plus 18% GST, taken from config/subscription.php.
 */
class CreateSubscriptionPlan extends Command
{
    protected $signature = 'subscription:create-plan';

    protected $description = 'Create the monthly Rs 999 + GST plan in Razorpay';

    public function handle(RazorpayClient $razorpay): int
    {
        if (! $razorpay->keyId() || ! config('subscription.razorpay.key_secret')) {
            $this->error('Set RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET in .env first.');

            return self::FAILURE;
        }

        try {
            $plan = $razorpay->createPlan();
        } catch (Throwable $e) {
            $this->error('Razorpay refused the request: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Plan created: '.$plan['id'].' ('.number_format(SubscriptionPricing::totalMinor() / 100, 2).' INR a month, GST included).');
        $this->line('Add this to .env, then run php artisan config:cache:');
        $this->line('RAZORPAY_PLAN_ID='.$plan['id']);

        return self::SUCCESS;
    }
}
