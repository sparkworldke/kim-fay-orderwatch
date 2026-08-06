<?php

namespace App\Services\Team;

use App\Models\User;

/**
 * Immutable result of PRD §7.1 identity resolution.
 *
 * Resolves an Acumatica rep identifier (employee number, rep code, or mapping
 * alias) to a single active user. The status distinguishes a clean resolution
 * from data-quality problems that require admin action:
 *
 *  - resolved   : exactly one active user at the winning priority, no conflict.
 *  - ambiguous  : duplicate active matches at the same priority.
 *  - conflict   : the identifier matched different active users at different priorities.
 *  - inactive   : only inactive users matched.
 *  - unresolved : no match at all.
 */
final class IdentityResolution
{
    public const STATUS_RESOLVED   = 'resolved';
    public const STATUS_AMBIGUOUS  = 'ambiguous';
    public const STATUS_CONFLICT   = 'conflict';
    public const STATUS_INACTIVE   = 'inactive';
    public const STATUS_UNRESOLVED = 'unresolved';

    /**
     * @param  list<array{user_id: int, name: string, matched_via: string, is_active: bool}>  $candidates
     */
    public function __construct(
        public readonly string $status,
        public readonly ?User $user,
        public readonly ?string $matchedVia,
        public readonly string $normalizedAlias,
        public readonly array $candidates = [],
        public readonly string $reason = '',
    ) {}

    public function resolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED && $this->user !== null;
    }

    /**
     * True when resolution could not pick a single user safely (duplicate or
     * cross-priority conflict). Such identifiers must not be silently used to
     * broaden customer scope — they require admin reconciliation.
     */
    public function isAmbiguous(): bool
    {
        return $this->status === self::STATUS_AMBIGUOUS || $this->status === self::STATUS_CONFLICT;
    }

    /**
     * @return array{status: string, matched_via: ?string, user_id: ?int, alias: string, reason: string, candidates: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'status'      => $this->status,
            'matched_via' => $this->matchedVia,
            'user_id'     => $this->user?->id,
            'alias'       => $this->normalizedAlias,
            'reason'      => $this->reason,
            'candidates'  => $this->candidates,
        ];
    }
}
