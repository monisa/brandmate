<?php

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\Membership;
use App\Domain\Rbac\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates (or resets) an e-mail-verified Company Admin with a company, for demos.
 *
 * The password is read from a file so it never appears in the command line or the
 * process list; use /dev/stdin to pipe it in:
 *
 *   printf '%s' "$PASSWORD" | php artisan demo:admin demo@example.com --password-file=/dev/stdin
 *
 * Running it again for the same e-mail resets the password. Super admins and
 * creator accounts are never touched.
 */
class CreateDemoAdmin extends Command
{
    protected $signature = 'demo:admin
        {email : Sign-in e-mail}
        {--password-file= : File that holds the password (use /dev/stdin to pipe it in)}
        {--name=Demo Admin : Display name}
        {--company=Demo Company : Company to create}';

    protected $description = 'Create or reset a verified Company Admin account for demos';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $file = (string) $this->option('password-file');
        $password = $file !== '' && is_readable($file) ? rtrim((string) file_get_contents($file), "\r\n") : '';

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email', 'max:255'], 'password' => ['required', Password::defaults()]],
        );
        if ($validator->fails()) {
            // Messages only; the password itself is never printed.
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user && ($user->is_super_admin || $user->account_type === 'creator')) {
            $this->error('That e-mail belongs to a super admin or creator account. Use a different one.');

            return self::FAILURE;
        }

        $created = ! $user;

        DB::transaction(function () use (&$user, $email, $password) {
            $user ??= new User(['email' => $email]);
            $user->forceFill([
                'name' => (string) $this->option('name'),
                'password' => $password,
                'email_verified_at' => now(),
            ])->save();

            if (! Membership::where('user_id', $user->id)->exists()) {
                $company = Company::create([
                    'name' => (string) $this->option('company'),
                    'slug' => Company::uniqueSlug((string) $this->option('company')),
                ]);

                Membership::create([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'role_id' => Role::findSystem(Role::COMPANY_ADMIN)->id,
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }
        });

        AuditLog::create([
            'user_id' => $user->id,
            'action' => $created ? 'admin.demo_account_created' : 'admin.demo_account_reset',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'created_at' => now(),
        ]);

        $this->info(($created ? 'Created' : 'Reset').' Company Admin '.$email.'. Sign in with the password you supplied.');

        return self::SUCCESS;
    }
}
