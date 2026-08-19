<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\DataTransferObjects\AiTool;
use App\Domain\Finance\Services\FinancialReportingService;
use App\Domain\Finance\Support\Money;
use App\Domain\Wishlist\Models\WishlistItem;
use App\Domain\Wishlist\Services\WishlistAffordabilityService;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The read-only AI financial assistant (CLAUDE.md §AI ASSISTANT). Builds
 * a fixed set of whitelisted tools, each closure bound to the current
 * $user and backed entirely by FinancialReportingService — the model
 * never gets a user id to supply itself, never touches the database, and
 * never computes a figure the backend hasn't already calculated. Every
 * amount handed to the model is pre-formatted (Money::formatMinor)
 * specifically so the model has no arithmetic left to do, not even a
 * minor-to-major unit conversion.
 */
class FinancialAssistantService
{
    public function __construct(
        private readonly AIProviderInterface $aiProvider,
        private readonly FinancialReportingService $reports,
    ) {}

    public function answerQuestion(User $user, string $question): string
    {
        return $this->aiProvider->answerQuestion($question, $this->buildTools($user));
    }

    /**
     * @return AiTool[]
     */
    private function buildTools(User $user): array
    {
        return [
            new AiTool(
                name: 'get_financial_summary',
                description: 'Get total income, total expenses, net cash flow, and savings rate for a given month.',
                parameters: $this->monthParameterSchema(),
                handler: function (array $input) use ($user) {
                    [$start, $end] = $this->resolveMonth($input);
                    $summary = $this->reports->getFinancialSummary($user, $start, $end);

                    return [
                        'month' => $start->format('F Y'),
                        'income' => Money::formatMinor($summary['income_minor']),
                        'expenses' => Money::formatMinor($summary['expense_minor']),
                        'net_cash_flow' => Money::formatMinor($summary['net_cash_flow_minor']),
                        'savings_rate_percent' => $summary['savings_rate_percent'],
                    ];
                },
            ),

            new AiTool(
                name: 'get_category_spending',
                description: 'Get spending broken down by category for a given month, highest first.',
                parameters: $this->monthParameterSchema(),
                handler: function (array $input) use ($user) {
                    [$start, $end] = $this->resolveMonth($input);

                    return $this->reports->getCategorySpending($user, $start, $end)
                        ->map(fn ($row) => ['category' => $row['name'], 'amount' => Money::formatMinor($row['amount_minor'])])
                        ->values()
                        ->all();
                },
            ),

            new AiTool(
                name: 'compare_financial_periods',
                description: 'Compare income and expenses between two months.',
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'period_a_month' => ['type' => 'string', 'description' => 'The earlier month, format YYYY-MM.'],
                        'period_b_month' => ['type' => 'string', 'description' => 'The later month to compare against it, format YYYY-MM.'],
                    ],
                    'required' => ['period_a_month', 'period_b_month'],
                    'additionalProperties' => false,
                ],
                handler: function (array $input) use ($user) {
                    [$aStart, $aEnd] = $this->resolveMonth(['month' => $input['period_a_month']]);
                    [$bStart, $bEnd] = $this->resolveMonth(['month' => $input['period_b_month']]);

                    $comparison = $this->reports->compareFinancialPeriods($user, $aStart, $aEnd, $bStart, $bEnd);

                    return [
                        'period_a' => $aStart->format('F Y'),
                        'period_b' => $bStart->format('F Y'),
                        'period_a_income' => Money::formatMinor($comparison['period_a']['income_minor']),
                        'period_a_expenses' => Money::formatMinor($comparison['period_a']['expense_minor']),
                        'period_b_income' => Money::formatMinor($comparison['period_b']['income_minor']),
                        'period_b_expenses' => Money::formatMinor($comparison['period_b']['expense_minor']),
                        'income_change_percent' => $comparison['income_change_percent'],
                        'expense_change_percent' => $comparison['expense_change_percent'],
                    ];
                },
            ),

            new AiTool(
                name: 'get_account_balances',
                description: "Get the user's financial accounts and their current balances.",
                parameters: ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                handler: fn () => $this->reports->getAccountBalances($user)
                    ->map(fn ($row) => ['account' => $row['name'], 'balance' => Money::formatMinor($row['balance_minor'])])
                    ->values()
                    ->all(),
            ),

            new AiTool(
                name: 'get_savings_goal_progress',
                description: 'Get all active savings goals with their target, amount allocated so far, and progress percentage.',
                parameters: ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                handler: fn () => $this->reports->getSavingsGoalProgress($user)
                    ->map(fn ($row) => [
                        'goal' => $row['title'],
                        'target' => Money::formatMinor($row['target_value']),
                        'allocated' => Money::formatMinor($row['allocated_minor']),
                        'progress_percent' => $row['progress_percent'],
                        'months_remaining_at_current_plan' => $row['months_remaining'],
                    ])
                    ->values()
                    ->all(),
            ),

            new AiTool(
                name: 'calculate_wishlist_affordability',
                description: 'Get Conservative/Current Trend/Aggressive affordability projections for a wishlist item by name.',
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'item_name' => ['type' => 'string', 'description' => 'The wishlist item name, or part of it.'],
                    ],
                    'required' => ['item_name'],
                    'additionalProperties' => false,
                ],
                handler: function (array $input) use ($user) {
                    $item = WishlistItem::query()
                        ->where('user_id', $user->id)
                        ->where('name', 'like', '%'.$input['item_name'].'%')
                        ->first();

                    if ($item === null) {
                        return ['error' => "No wishlist item matching \"{$input['item_name']}\" was found."];
                    }

                    $scenarios = app(WishlistAffordabilityService::class)->calculate($item);

                    if ($scenarios === null) {
                        return [
                            'item' => $item->name,
                            'error' => 'This item has no linked savings goal with a planned monthly contribution, so affordability cannot be calculated.',
                        ];
                    }

                    return [
                        'item' => $item->name,
                        'remaining_amount' => Money::formatMinor($item->remainingAmountMinor()),
                        'conservative_months' => $scenarios['conservative']['months'],
                        'current_trend_months' => $scenarios['current_trend']['months'],
                        'aggressive_months' => $scenarios['aggressive']['months'],
                    ];
                },
            ),

            new AiTool(
                name: 'get_transactions',
                description: 'Get a list of recent individual transactions for a given month.',
                parameters: $this->monthParameterSchema(),
                handler: function (array $input) use ($user) {
                    [$start, $end] = $this->resolveMonth($input);

                    return $this->reports->getTransactions($user, $start, $end)
                        ->map(fn ($row) => [
                            'date' => $row['occurred_at']->format('Y-m-d'),
                            'type' => $row['journal_type'],
                            'description' => $row['description'],
                            'amount' => Money::formatMinor($row['amount_minor']),
                        ])
                        ->values()
                        ->all();
                },
            ),
        ];
    }

    private function monthParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'month' => ['type' => 'string', 'description' => 'Format YYYY-MM. Defaults to the current month if omitted.'],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveMonth(array $input): array
    {
        $month = $input['month'] ?? null;

        $start = $month ? Carbon::createFromFormat('Y-m', $month)->startOfMonth() : now()->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }
}
