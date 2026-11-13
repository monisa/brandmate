<?php

namespace App\Domain\Audit\Models;

use App\Domain\Companies\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'user_id', 'action', 'subject_type', 'subject_id',
        'metadata', 'ip_address', 'request_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
