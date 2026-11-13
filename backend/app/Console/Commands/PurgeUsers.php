<?php

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes every account except the platform super admins, together with the
 * data those accounts own: companies (with their roles, memberships,
 * invitations and shortlists), creator profiles, tokens and sessions.
 * Sample influencers (no owner) and audit logs are kept.
 *
 * Writes a JSON backup of every deleted row to storage/app/private/backups first.
 *
 *   php artisan users:purge --dry-run     shows what would be deleted
 *   php artisan users:purge --force       deletes
 */
class PurgeUsers extends Command
{
    protected $signature = 'users:purge {--dry-run : Only report what would be deleted} {--force : Delete without asking}';

    protected $description = 'Delete all users except super admins, with the data they own';

    public function handle(): int
    {
        $keepIds = User::where('is_super_admin', true)->pluck('id');
        if ($keepIds->isEmpty()) {
            $this->error('No super admin exists. Refusing to delete every account, nobody could sign in afterwards.');

            return self::FAILURE;
        }

        $userIds = User::whereNotIn('id', $keepIds)->pluck('id');
        $emails = User::whereIn('id', $userIds)->pluck('email');
        // Companies a super admin belongs to are kept; every other company goes.
        $companyIds = DB::table('companies')
            ->whereNotIn('id', DB::table('company_users')->whereIn('user_id', $keepIds)->select('company_id'))
            ->pluck('id');
        $creatorIds = DB::table('influencers')->whereIn('user_id', $userIds)->pluck('id');
        $shortlistIds = DB::table('shortlists')->whereIn('company_id', $companyIds)->pluck('id');
        $tokenable = (new User)->getMorphClass();

        $plan = [
            'users' => DB::table('users')->whereIn('id', $userIds),
            'companies' => DB::table('companies')->whereIn('id', $companyIds),
            'company_users' => DB::table('company_users')->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereIn('user_id', $userIds)),
            'invitations' => DB::table('invitations')->whereIn('company_id', $companyIds),
            'roles' => DB::table('roles')->whereIn('company_id', $companyIds),
            'collaboration_requests' => DB::table('collaboration_requests')
                ->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereIn('influencer_id', $creatorIds)),
            'shortlists' => DB::table('shortlists')->whereIn('id', $shortlistIds),
            'shortlist_influencers' => DB::table('shortlist_influencers')->whereIn('shortlist_id', $shortlistIds),
            'influencers (creator profiles)' => DB::table('influencers')->whereIn('id', $creatorIds),
            'social_profiles' => DB::table('social_profiles')->whereIn('influencer_id', $creatorIds),
            'creator_packages' => DB::table('creator_packages')->whereIn('influencer_id', $creatorIds),
            'personal_access_tokens' => DB::table('personal_access_tokens')->where('tokenable_type', $tokenable)->whereIn('tokenable_id', $userIds),
            'notifications' => DB::table('notifications')->where('notifiable_type', $tokenable)->whereIn('notifiable_id', $userIds),
            'sessions' => DB::table('sessions')->whereIn('user_id', $userIds),
            'password_reset_tokens' => DB::table('password_reset_tokens')->whereIn('email', $emails),
        ];

        $counts = array_map(fn ($query) => (clone $query)->count(), $plan);
        $this->info('Keeping '.$keepIds->count().' super admin account(s).');
        $this->table(['Table', 'Rows to delete'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('Permanently delete these rows?')) {
            return self::FAILURE;
        }

        $backup = collect($plan)->map(fn ($query) => (clone $query)->get())->all();
        $file = 'backups/users-purge-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Backup written to '.Storage::disk('local')->path($file));

        DB::transaction(function () use ($plan, $counts) {
            // Children before parents: memberships restrict deleting their roles.
            foreach ([
                'collaboration_requests', 'shortlist_influencers', 'shortlists', 'invitations', 'company_users', 'roles', 'companies',
                'creator_packages', 'social_profiles', 'influencers (creator profiles)',
                'personal_access_tokens', 'notifications', 'sessions', 'password_reset_tokens', 'users',
            ] as $table) {
                (clone $plan[$table])->delete();
            }

            AuditLog::create([
                'action' => 'admin.users_purged',
                'metadata' => $counts,
                'created_at' => now(),
            ]);
        });

        $this->info('Done. '.$counts['users'].' user(s) deleted.');

        return self::SUCCESS;
    }
}
