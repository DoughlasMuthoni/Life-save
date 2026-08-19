<?php

namespace App\Domain\Wishlist\Services;

use App\Domain\Goals\Enums\AllocationEventType;
use App\Domain\Goals\Models\Goal;
use App\Domain\Wishlist\Models\WishlistItem;

/**
 * Deterministic Conservative / Current Trend / Aggressive affordability
 * scenarios (CLAUDE.md §WISHLIST). All three come from actual financial
 * data — a goal's planned monthly contribution and its real allocation
 * history — never an invented number. Nothing here calls an AI provider;
 * if an AI-generated narrative is ever added on top of this, it explains
 * these figures, it doesn't calculate its own.
 */
class WishlistAffordabilityService
{
    private const CONSERVATIVE_MULTIPLIER = 0.5;

    private const AGGRESSIVE_MULTIPLIER = 1.5;

    private const TREND_LOOKBACK_MONTHS = 3;

    /**
     * Null means "not calculable" — no linked goal, or no planned monthly
     * contribution to project from. CLAUDE.md: don't invent a number here.
     *
     * @return array{conservative: array{monthly_amount_minor: int, months: ?int}, current_trend: array{monthly_amount_minor: int, months: ?int}, aggressive: array{monthly_amount_minor: int, months: ?int}}|null
     */
    public function calculate(WishlistItem $item): ?array
    {
        $goal = $item->linkedGoal;

        if ($goal === null || $goal->monthly_contribution_minor === null || $goal->monthly_contribution_minor <= 0) {
            return null;
        }

        $remaining = $item->remainingAmountMinor();
        $planned = $goal->monthly_contribution_minor;

        $monthlyAmounts = [
            'conservative' => (int) round($planned * self::CONSERVATIVE_MULTIPLIER),
            'current_trend' => $this->currentTrendMonthlyMinor($goal) ?? $planned,
            'aggressive' => (int) round($planned * self::AGGRESSIVE_MULTIPLIER),
        ];

        $scenarios = [];

        foreach ($monthlyAmounts as $key => $monthlyAmount) {
            $scenarios[$key] = [
                'monthly_amount_minor' => $monthlyAmount,
                'months' => match (true) {
                    $remaining <= 0 => 0,
                    $monthlyAmount <= 0 => null,
                    default => (int) ceil($remaining / $monthlyAmount),
                },
            ];
        }

        return $scenarios;
    }

    /**
     * The actual average monthly net allocation over the last few months,
     * or null if there's no recent allocation history to average — the
     * caller falls back to the goal's planned rate in that case.
     */
    private function currentTrendMonthlyMinor(Goal $goal): ?int
    {
        $since = now()->subMonths(self::TREND_LOOKBACK_MONTHS)->startOfMonth();

        $allocated = $goal->allocationEvents()
            ->where('event_type', AllocationEventType::ALLOCATE)
            ->where('created_at', '>=', $since)
            ->sum('amount_minor');

        $released = $goal->allocationEvents()
            ->where('event_type', AllocationEventType::RELEASE)
            ->where('created_at', '>=', $since)
            ->sum('amount_minor');

        $net = (int) $allocated - (int) $released;

        if ($net <= 0) {
            return null;
        }

        return (int) round($net / self::TREND_LOOKBACK_MONTHS);
    }
}
