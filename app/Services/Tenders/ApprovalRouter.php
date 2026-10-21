<?php

namespace App\Services\Tenders;

use App\Authorization\PermissionMap;
use App\Enums\ApprovalLevel;
use App\Enums\DepartmentRole;
use App\Enums\TenderMethod;
use App\Enums\TenderType;
use App\Enums\UserRole;
use App\Models\Tender;
use App\Models\TenderAward;
use App\Models\User;
use App\Support\BusinessSettings;

/**
 * Approval routing (Spec 03 4.10 / BR-T.10, Spec 08 9.3, user decision 2026-10-18: above
 * 60 000 000 UZS the CEO decides).
 *
 * Publishing: head of the tender department (or a manager) when the budget is ≤ the CEO threshold;
 * the CEO for single-source tenders or budgets above it.
 * Award per lot: L1 head unless any CEO condition holds (amount above the CEO threshold or unknown
 * in UZS, not rank 1, fewer than 2 valid bids, above budget + 10 %); finance co-approves above T2.
 * Maker ≠ checker: never the owner, requester, award proposer or an employee who entered a bid.
 */
class ApprovalRouter
{
    public function __construct(protected TenderFx $fx) {}

    /**
     * @return array{levels: list<ApprovalLevel>, reasons: list<string>}
     */
    public function publishLevels(Tender $tender): array
    {
        $reasons = [];

        if ($tender->method === TenderMethod::SingleSource) {
            $reasons[] = 'single_source';
        }

        if ($tender->budget_minor !== null) {
            $uzs = $this->fx->toUzs($tender, $tender->budget_minor, (string) ($tender->budget_currency ?? $tender->evaluation_currency));

            if ($uzs === null || $uzs > BusinessSettings::int('tenders.ceo_threshold_minor')) {
                $reasons[] = $uzs === null ? 'amount_unknown' : 'above_ceo_threshold';
            }
        }

        return ['levels' => $reasons === [] ? [ApprovalLevel::Head] : [ApprovalLevel::Ceo], 'reasons' => $reasons];
    }

    /**
     * @return array{levels: list<ApprovalLevel>, reasons: list<string>}
     */
    public function awardLevels(Tender $tender, int $landedCostMinor, ?int $rank, int $validBids): array
    {
        $reasons = [];
        $uzs = $this->fx->toUzs($tender, $landedCostMinor, $tender->evaluation_currency);

        if ($uzs === null) {
            $reasons[] = 'amount_unknown';
        } elseif ($uzs > BusinessSettings::int('tenders.ceo_threshold_minor')) {
            $reasons[] = 'above_ceo_threshold';
        }

        if ($rank !== 1) {
            $reasons[] = 'not_rank_one';
        }

        if ($validBids < 2) {
            $reasons[] = 'few_bids';
        }

        if ($tender->budget_minor !== null && $uzs !== null) {
            $budgetUzs = $this->fx->toUzs($tender, $tender->budget_minor, (string) ($tender->budget_currency ?? $tender->evaluation_currency));
            $limit = $budgetUzs !== null ? bcdiv(bcmul((string) $budgetUzs, (string) (10000 + BusinessSettings::int('tenders.over_budget_ceo_bp'))), '10000', 0) : null;

            if ($limit !== null && bccomp((string) $uzs, $limit, 0) > 0) {
                $reasons[] = 'over_budget';
            }
        }

        $levels = [$reasons === [] ? ApprovalLevel::Head : ApprovalLevel::Ceo];

        if ($uzs === null || $uzs > BusinessSettings::int('tenders.t2_minor')) {
            $levels[] = ApprovalLevel::Finance;
        }

        return ['levels' => $levels, 'reasons' => $reasons];
    }

    /**
     * Whether the user may decide at the level for this tender (and award).
     */
    public function canDecide(User $user, Tender $tender, ApprovalLevel $level, ?TenderAward $award = null): bool
    {
        if (! $user->isInternal() || ! $user->is_active || $user->hasRole(UserRole::Admin)) {
            return false;
        }

        if (in_array($user->id, array_filter([$tender->owner_id, $tender->requested_by, $award?->proposed_by]), true)) {
            return false;
        }

        if ($award !== null && $tender->bids()->where('entered_by', $user->id)->exists()) {
            return false;
        }

        return match ($level) {
            ApprovalLevel::Head => PermissionMap::allows($user, 'tenders.approve') && (
                $user->hasRole(UserRole::Manager)
                || TenderAccess::isDepartmentMember($user, $tender, DepartmentRole::Head)
                || ($award !== null && $tender->type === TenderType::Transport && PermissionMap::matchesGrant($user, 'dept:purchase:head'))
            ),
            ApprovalLevel::Ceo => PermissionMap::allows($user, 'tenders.ceo_approve'),
            ApprovalLevel::Finance => PermissionMap::allows($user, 'tenders.finance_approve'),
        };
    }
}
