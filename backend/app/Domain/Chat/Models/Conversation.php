<?php

namespace App\Domain\Chat\Models;

use App\Domain\Companies\Models\Company;
use App\Domain\Influencers\Models\Influencer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat between one brand company and one creator. It is not tenant-scoped, because both sides
 * read it; the controller only ever reaches it through the signed-in person's own company or profile.
 */
class Conversation extends Model
{
    public const BRAND = 'brand';

    public const CREATOR = 'creator';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** The column that holds the newest message this side has read. */
    public static function readColumn(string $side): string
    {
        return $side === self::BRAND ? 'brand_last_read_id' : 'creator_last_read_id';
    }
}
