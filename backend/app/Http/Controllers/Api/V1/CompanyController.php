<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Companies\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function show(TenantContext $tenant): CompanyResource
    {
        return new CompanyResource($tenant->company());
    }

    public function update(Request $request, TenantContext $tenant, AuditLogger $audit): CompanyResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'website' => ['nullable', 'url', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['required', 'timezone'],
            // Contact details that creators see once they subscribe.
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+()\-\s]{6,25}$/'],
            'channel_name' => ['nullable', 'string', 'max:120'],
            'industry' => ['nullable', Rule::in(config('platform.industries'))],
        ]);

        $company = $tenant->company();
        $company->update($data);
        $audit->log('company.updated', $company, ['fields' => array_keys($company->getChanges())]);

        return new CompanyResource($company);
    }
}
