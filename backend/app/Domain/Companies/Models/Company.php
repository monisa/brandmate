<?php

namespace App\Domain\Companies\Models;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Companies\CompanyScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Str;

class Company extends Model
{
    protected $fillable = ['name', 'slug', 'website', 'country', 'timezone', 'status', 'phone', 'channel_name', 'industry'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function memberships(): HasMany
    {
        // Already constrained by the foreign key, so the tenant scope is redundant here.
        return $this->hasMany(Membership::class)->withoutGlobalScope(CompanyScope::class);
    }

    /** Set by the admin overview so a new company can show who signed it up. */
    public function owner(): HasOneThrough
    {
        return $this->hasOneThrough(User::class, Membership::class, 'company_id', 'id', 'id', 'user_id')
            ->withoutGlobalScopes()
            ->orderBy('company_users.id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Brands can search, shortlist and send requests only once the platform team has approved them. */
    public function isApproved(): bool
    {
        return $this->approval_status === ApprovalStatus::APPROVED;
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
