<?php

namespace App\Domain\Admin;

use App\Domain\Companies\CompanyScope;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\Membership;
use App\Domain\Influencers\Models\Influencer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Permanently deletes accounts for the Super Admin, together with what they own.
 *
 * Never touches a super admin or the person doing the deleting. Child rows (a creator's
 * accounts, packages and requests; a company's roles, invitations, shortlists and
 * requests) go with their parent through the database's cascading foreign keys, so
 * nothing is left orphaned. Audit logs are kept.
 */
class AccountRemoval
{
    /**
     * Deletes users. A creator's profile goes with the login; a company left with no members goes too.
     *
     * @param  list<int>  $userIds
     * @return array{users: int, skipped: int, companies: int}
     */
    public function deleteUsers(array $userIds, User $actor): array
    {
        $users = User::whereIn('id', $userIds)->get();
        $deletable = $users->reject(fn (User $u) => $u->isSuperAdmin() || $u->id === $actor->id);
        $ids = $deletable->pluck('id')->all();
        $skipped = count($userIds) - count($ids);

        if (! $ids) {
            return ['users' => 0, 'skipped' => $skipped, 'companies' => 0];
        }

        $morph = (new User)->getMorphClass();
        $emails = $deletable->pluck('email')->all();
        $companyIds = Membership::withoutGlobalScope(CompanyScope::class)->whereIn('user_id', $ids)->pluck('company_id')->unique()->all();
        $companiesRemoved = 0;

        DB::transaction(function () use ($ids, $emails, $morph, $companyIds, &$companiesRemoved) {
            Influencer::whereIn('user_id', $ids)->delete(); // cascades accounts, packages, requests, views
            DB::table('personal_access_tokens')->where('tokenable_type', $morph)->whereIn('tokenable_id', $ids)->delete();
            DB::table('notifications')->where('notifiable_type', $morph)->whereIn('notifiable_id', $ids)->delete();
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
            DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
            User::whereIn('id', $ids)->delete(); // cascades memberships

            // Companies that just lost their last member.
            $companiesRemoved = Company::whereIn('id', $companyIds)->whereDoesntHave('memberships')->delete();
        });

        return ['users' => count($ids), 'skipped' => $skipped, 'companies' => $companiesRemoved];
    }

    /**
     * Deletes companies and the people who belong to them.
     *
     * @param  list<int>  $companyIds
     * @return array{companies: int, users: int, skipped: int}
     */
    public function deleteCompanies(array $companyIds, User $actor): array
    {
        $existing = Company::whereIn('id', $companyIds)->pluck('id')->all();

        $memberIds = Membership::withoutGlobalScope(CompanyScope::class)->whereIn('company_id', $existing)->pluck('user_id')->unique()->all();
        $removedUsers = $memberIds ? $this->deleteUsers($memberIds, $actor) : ['users' => 0, 'skipped' => 0, 'companies' => 0];

        // Companies that still exist (a protected super admin is a member) are deleted too.
        $remaining = Company::whereIn('id', $existing)->delete();

        return [
            'companies' => $removedUsers['companies'] + $remaining,
            'users' => $removedUsers['users'],
            'skipped' => count($companyIds) - count($existing),
        ];
    }

    /**
     * Deletes creators who signed up themselves, with their login. Profiles without a login
     * (sample and imported data) cannot be deleted here.
     *
     * @param  list<int>  $creatorIds
     * @return array{creators: int, skipped: int}
     */
    public function deleteCreators(array $creatorIds, User $actor): array
    {
        $creators = Influencer::whereIn('id', $creatorIds)->whereNotNull('user_id')->get();
        $removed = $this->deleteUsers($creators->pluck('user_id')->all(), $actor);

        return [
            'creators' => $removed['users'],
            'skipped' => count($creatorIds) - $removed['users'],
        ];
    }
}
