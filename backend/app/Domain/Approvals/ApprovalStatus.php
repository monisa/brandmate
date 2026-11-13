<?php

namespace App\Domain\Approvals;

/**
 * Where a brand (company) or a creator profile stands with the platform team.
 *
 * Existing rows default to approved in the database, so adding approvals never hides
 * anything that was already live; signup flows set PENDING explicitly in code.
 */
final class ApprovalStatus
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const ALL = [self::PENDING, self::APPROVED, self::REJECTED];
}
