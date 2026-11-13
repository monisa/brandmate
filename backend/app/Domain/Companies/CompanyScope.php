<?php

namespace App\Domain\Companies;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant-owned model to the current company.
 *
 * Fails closed: when no tenant is set the query returns nothing. Code that
 * legitimately needs cross-tenant access (super-admin screens, token lookups,
 * console commands, queued jobs) must either set the TenantContext or opt out
 * with withoutGlobalScope(CompanyScope::class).
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = app(TenantContext::class)->id();

        if ($companyId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $companyId);
    }
}
