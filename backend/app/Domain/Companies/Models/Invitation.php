<?php

namespace App\Domain\Companies\Models;

use App\Domain\Companies\BelongsToCompany;
use App\Domain\Rbac\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitation extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'email', 'role_id', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
