<?php

namespace App\Domain\Requests\Models;

use App\Domain\Companies\BelongsToCompany;
use App\Domain\Creators\Models\CreatorPackage;
use App\Domain\Influencers\Models\Influencer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A brand's request to work with a creator. Brand-side queries are tenant-scoped;
 * the creator's inbox opts out of the scope and filters by influencer_id instead.
 */
class CollaborationRequest extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::PENDING, self::ACCEPTED, self::DECLINED, self::WITHDRAWN];

    // company_id is set from the tenant, never from request input.
    protected $fillable = [
        'company_id', 'influencer_id', 'requested_by', 'package_id', 'title', 'message',
        'budget_minor', 'currency', 'due_date', 'status',
    ];

    protected function casts(): array
    {
        return [
            'budget_minor' => 'integer',
            'due_date' => 'date',
            'responded_at' => 'datetime',
        ];
    }

    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(CreatorPackage::class);
    }

    /**
     * The request as the API shows it. Contact e-mails are shared only once the
     * creator has accepted, and only with the other party.
     *
     * @return array<string, mixed>
     */
    public function toApi(string $viewer): array
    {
        $accepted = $this->status === self::ACCEPTED;

        $data = [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'budget' => $this->budget_minor === null ? null : $this->budget_minor / 100,
            'currency' => $this->currency,
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'creator_response' => $this->creator_response,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'package' => $this->package ? [
                'id' => $this->package->id,
                'title' => $this->package->title,
                'price' => $this->package->price_minor / 100,
                'currency' => $this->package->currency,
            ] : null,
        ];

        if ($viewer === 'creator') {
            $data['brand'] = [
                'company' => $this->company?->name,
                'requested_by' => $this->requester?->name,
                'email' => $accepted ? $this->requester?->email : null,
            ];

            return $data;
        }

        $creator = $this->influencer;
        $profile = $creator?->profiles->sortBy('id')->first();
        $data['creator'] = [
            'id' => $creator?->id,
            'profile_id' => $profile?->id,
            'name' => $creator?->name,
            'username' => $profile?->username,
            'platform' => $profile?->platform,
            'avatar_url' => $profile?->avatar_url,
            'email' => $accepted ? $creator?->user?->email : null,
        ];
        $data['requested_by'] = $this->requester?->name;

        return $data;
    }
}
