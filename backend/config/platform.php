<?php

return [
    // Angular app origin: used for CORS and for links inside e-mails.
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:4200'), '/'),

    // Lifetime of API tokens issued at login.
    'token_ttl_days' => (int) env('API_TOKEN_TTL_DAYS', 7),

    'invitation_ttl_days' => (int) env('INVITATION_TTL_DAYS', 7),

    // Categories a creator can choose from.
    'categories' => [
        'Beauty', 'Comedy', 'Education', 'Fashion', 'Finance', 'Fitness', 'Food', 'Gaming',
        'Lifestyle', 'Music', 'Parenting', 'Technology', 'Travel',
    ],

    // Industries a brand can choose from.
    'industries' => [
        'Beauty & personal care', 'Education', 'Entertainment', 'Fashion & apparel', 'Finance', 'Food & beverage',
        'Health & fitness', 'Home & lifestyle', 'Retail & e-commerce', 'Technology', 'Travel & hospitality', 'Other',
    ],

    // Currency for creator packages. One currency keeps pricing and payouts simple for now.
    'currency' => env('PLATFORM_CURRENCY', 'INR'),

    // Fictional influencers for development. Turn off in production.
    'seed_sample_influencers' => (bool) env('SEED_SAMPLE_INFLUENCERS', true),

    // Optional platform owner created by `php artisan db:seed`.
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],
];
