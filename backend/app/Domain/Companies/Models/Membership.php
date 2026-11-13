<?php

namespace App\Domain\Companies\Models;

use App\Domain\Companies\BelongsToCompany;
use App\Domain\Rbac\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's seat in a company, with the role they hold there.
 */
class Membership extends Model
{
    use BelongsToCompany;

    protected $table = 'company_users';

    protected $fillable = ['company_id', 'user_id', 'role_id', 'status', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
