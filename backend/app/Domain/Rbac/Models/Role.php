<?php

namespace App\Domain\Rbac\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const COMPANY_ADMIN = 'company_admin';

    public const TEAM_MEMBER = 'team_member';

    protected $fillable = ['company_id', 'name', 'slug', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNull('company_id');
    }

    public static function findSystem(string $slug): self
    {
        return static::query()->system()->where('slug', $slug)->firstOrFail();
    }
}
