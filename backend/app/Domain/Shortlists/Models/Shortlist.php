<?php

namespace App\Domain\Shortlists\Models;

use App\Domain\Companies\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shortlist extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'created_by'];

    public function items(): HasMany
    {
        return $this->hasMany(ShortlistItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
