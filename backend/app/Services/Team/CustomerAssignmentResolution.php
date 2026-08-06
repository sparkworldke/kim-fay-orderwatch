<?php

namespace App\Services\Team;

/**
 * Immutable result of PRD §7.2 customer assignment precedence resolution.
 *
 * For a single customer, resolves the one canonical servicing rep by walking
 * the precedence ladder (manual override → workbook → main account → region →
 * customer rep alias → most recent SO rep alias). Lower priority numbers win.
 *
 * Every source that contributed a candidate is retained in {@see $candidates}
 * so that lower-precedence matches remain visible in the audit explanation,
 * even though they did not win.
 */
final class CustomerAssignmentResolution
{
    public const SOURCE_MANUAL_OVERRIDE   = 'manual_override';
    public const SOURCE_WORKBOOK_CUSTOMER = 'workbook_customer';
    public const SOURCE_MAIN_ACCOUNT      = 'main_account';
    public const SOURCE_REGION            = 'region';
    public const SOURCE_CUSTOMER_REP_ALIAS = 'customer_rep_alias';
    public const SOURCE_SO_REP_ALIAS       = 'so_rep_alias';
    public const SOURCE_UNRESOLVED         = 'unresolved';

    /**
     * @param  list<array{source: string, priority: int, user_id: ?int, rule_id: ?int, assignment_id: ?int, note: string}>  $candidates
     */
    public function __construct(
        public readonly string $customerAcumaticaId,
        public readonly ?int $userId,
        public readonly string $winningSource,
        public readonly int $winningPriority,
        public readonly ?int $assignmentRuleId,
        public readonly array $candidates = [],
        public readonly string $resolutionReason = '',
    ) {}

    public function resolved(): bool
    {
        return $this->userId !== null && $this->winningSource !== self::SOURCE_UNRESOLVED;
    }

    public function ambiguous(): bool
    {
        return $this->userId === null && $this->candidates !== [];
    }

    /**
     * @return array{customer_acumatica_id: string, user_id: ?int, winning_source: string, winning_priority: int, assignment_rule_id: ?int, resolution_reason: string, candidates: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'customer_acumatica_id' => $this->customerAcumaticaId,
            'user_id'               => $this->userId,
            'winning_source'        => $this->winningSource,
            'winning_priority'      => $this->winningPriority,
            'assignment_rule_id'    => $this->assignmentRuleId,
            'resolution_reason'     => $this->resolutionReason,
            'candidates'            => $this->candidates,
        ];
    }
}
