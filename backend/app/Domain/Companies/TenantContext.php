<?php

namespace App\Domain\Companies;

use App\Domain\Companies\Models\Company;

/**
 * Holds the company the current request (or job) is acting for.
 * Bound as a scoped singleton and populated by the `tenant` middleware.
 */
class TenantContext
{
    private ?Company $company = null;

    public function set(Company $company): void
    {
        $this->company = $company;
    }

    public function forget(): void
    {
        $this->company = null;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }
}
