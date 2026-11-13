<?php

namespace App\Models;

use App\Domain\Authentication\Notifications\ResetPasswordNotification;
use App\Domain\Authentication\Notifications\VerifyEmailNotification;
use App\Domain\Companies\CompanyScope;
use App\Domain\Companies\Models\Membership;
use App\Domain\Influencers\Models\Influencer;
use App\Domain\Rbac\PermissionRegistry;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // is_super_admin and email_verified_at are deliberately not mass assignable.
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * The user's active company membership. The MVP allows one company per
     * user; the tenant scope is skipped because this lookup is what
     * establishes the tenant in the first place.
     */
    public function membership(): HasOne
    {
        return $this->hasOne(Membership::class)
            ->withoutGlobalScope(CompanyScope::class)
            ->where('status', 'active');
    }

    /** The creator profile owned by a creator account. */
    public function creatorProfile(): HasOne
    {
        return $this->hasOne(Influencer::class);
    }

    public function isCreator(): bool
    {
        return $this->account_type === 'creator';
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /** @return list<string> */
    public function permissionSlugs(): array
    {
        if ($this->isSuperAdmin()) {
            return [...array_keys(PermissionRegistry::PERMISSIONS), 'admin.manage'];
        }

        return $this->membership?->role?->permissions->pluck('slug')->values()->all() ?? [];
    }

    public function hasPermission(string $slug): bool
    {
        return $this->isSuperAdmin() || in_array($slug, $this->permissionSlugs(), true);
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
