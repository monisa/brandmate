<?php

namespace App\Domain\Companies;

use App\Domain\Companies\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Add to every tenant-owned model (shortlists, campaigns, reports, ...).
 * The table must have a `company_id` column.
 *
 * Note: tenant-owned models must not be resolved through implicit route
 * model binding, because bindings run before the `tenant` middleware.
 * Look them up by id inside the controller instead.
 */
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            if (empty($model->company_id)) {
                $model->company_id = app(TenantContext::class)->id();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
