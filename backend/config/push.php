<?php

return [
    /*
    | Web Push (the notification that arrives even when the site is closed). Generate the keys once
    | with `php artisan push:keys` and put them in .env. Without them push is simply switched off.
    */
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
    'subject' => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'hello@example.com')),
];
