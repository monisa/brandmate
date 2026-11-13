<?php

namespace App\Domain\Companies\Models;

use App\Domain\Companies\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    protected $fillable = ['name', 'slug', 'website', 'country', 'timezone', 'status'];

    public function memberships(): HasMany
    {
        // Already constrained by the foreign key, so the tenant scope is redundant here.
        return $this->hasMany(Membership::class)->withoutGlobalScope(CompanyScope::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
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
