<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class PushKeys extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Create the VAPID key pair for Web Push (run once, then put both lines in .env)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
