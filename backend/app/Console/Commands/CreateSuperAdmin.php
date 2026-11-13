<?php

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates a platform super admin, or resets the password of an existing one. The password is
 * read from standard input or a file so it never appears in the command line history or in code.
 * Brand and creator accounts are never converted: use a fresh e-mail address.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'admin:super
        {email : Sign-in e-mail}
        {--password-file= : File that holds the password, or - to read it from standard input}
        {--name=Platform Admin : Display name}';

    protected $description = 'Create a super admin account, or reset the password of an existing one';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $file = (string) $this->option('password-file');
        $raw = match (true) {
            $file === '-' => stream_get_contents(fopen('php://stdin', 'r')),
            $file !== '' && is_readable($file) => file_get_contents($file),
            default => '',
        };
        $password = rtrim((string) $raw, "\r\n");

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email', 'max:255'], 'password' => ['required', Password::defaults()]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user && ! $user->is_super_admin) {
            $this->error('That e-mail already belongs to a brand or creator account. Use a different one.');

            return self::FAILURE;
        }

        $created = ! $user;
        $user ??= new User(['email' => $email]);
        $user->forceFill([
            'name' => $created ? (string) $this->option('name') : $user->name,
            'password' => $password,
            'is_super_admin' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => $created ? 'admin.super_admin_created' : 'admin.super_admin_password_reset',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'created_at' => now(),
        ]);

        $this->info(($created ? 'Created' : 'Reset').' super admin '.$email.'. Sign in with the password you supplied.');

        return self::SUCCESS;
    }
}
