<?php

return [
    /*
    | Until this is true nobody is locked out: contact details are visible to everyone.
    | Turn it on (SUBSCRIPTION_ENFORCED=true) only after Razorpay is connected, otherwise
    | nobody would be able to pay to unlock anything.
    */
    'enforced' => (bool) env('SUBSCRIPTION_ENFORCED', false),

    // The monthly price in paise, before GST: Rs 999. GST is added on top.
    'price_minor' => 99900,
    'gst_percent' => 18,
    'currency' => 'INR',

    // A failed renewal keeps access for this many days while Razorpay retries.
    'grace_days' => 3,

    // Shown instead of a phone number, e-mail or channel name that is locked.
    'mask' => 'XXXxx',

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        // Create it once with: php artisan subscription:create-plan
        'plan_id' => env('RAZORPAY_PLAN_ID'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],
];
