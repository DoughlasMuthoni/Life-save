<?php

namespace Tests\Unit\Domain\AI;

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\DataTransferObjects\AiExtractedTransaction;
use App\Domain\AI\DataTransferObjects\AiTool;
use App\Domain\AI\Services\FinancialAssistantService;
use App\Domain\Finance\Services\TransactionService;
use App\Domain\Wishlist\Services\WishlistService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class FinancialAssistantServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    /**
     * Captures the tools the service builds, without simulating any real
     * model tool-picking logic — that's Anthropic's job, not ours to fake.
     */
    private function captureTools(User $user): Collection
    {
        $captured = null;

        $capturingProvider = new class($captured) implements AIProviderInterface
        {
            public ?array $tools = null;

            public function __construct(&$captured) {}

            public function parseFinancialMessage(string $normalizedText): ?AiExtractedTransaction
            {
                return null;
            }

            public function answerQuestion(string $question, array $tools): string
            {
                $this->tools = $tools;

                return 'captured';
            }
        };

        $this->app->instance(AIProviderInterface::class, $capturingProvider);

        app(FinancialAssistantService::class)->answerQuestion($user, 'irrelevant question');

        return collect($capturingProvider->tools)->keyBy('name');
    }

    private function toolNamed(Collection $tools, string $name): AiTool
    {
        return $tools->get($name);
    }

    public function test_it_exposes_the_expected_whitelisted_tools(): void
    {
        $user = User::factory()->create();

        $tools = $this->captureTools($user);

        $this->assertEqualsCanonicalizing(
            ['get_financial_summary', 'get_category_spending', 'compare_financial_periods', 'get_account_balances', 'get_savings_goal_progress', 'calculate_wishlist_affordability', 'get_transactions'],
            $tools->keys()->all(),
        );
    }

    public function test_get_financial_summary_tool_returns_correctly_formatted_real_data(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);
        $transactions = app(TransactionService::class);

        $transactions->recordIncome($user, $mpesa, $salary, 1000000, Carbon::parse('2025-05-01'));
        $transactions->recordExpense($user, $mpesa, $groceries, 300000, Carbon::parse('2025-05-05'));

        $tools = $this->captureTools($user);
        $result = ($this->toolNamed($tools, 'get_financial_summary')->handler)(['month' => '2025-05']);

        $this->assertSame('KSh 10,000.00', $result['income']);
        $this->assertSame('KSh 3,000.00', $result['expenses']);
        $this->assertSame('KSh 7,000.00', $result['net_cash_flow']);
    }

    public function test_get_category_spending_tool_returns_amounts_as_preformatted_strings_not_raw_integers(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);
        $transactions = app(TransactionService::class);

        $transactions->recordIncome($user, $mpesa, $salary, 1000000, Carbon::parse('2025-05-01'));
        $transactions->recordExpense($user, $mpesa, $groceries, 250000, Carbon::parse('2025-05-05'));

        $tools = $this->captureTools($user);
        $result = ($this->toolNamed($tools, 'get_category_spending')->handler)(['month' => '2025-05']);

        $this->assertSame('Groceries', $result[0]['category']);
        $this->assertIsString($result[0]['amount']);
        $this->assertSame('KSh 2,500.00', $result[0]['amount']);
    }

    public function test_get_account_balances_tool_only_returns_this_users_accounts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->createFinancialAccount($owner, 'M-Pesa');
        $this->createFinancialAccount($other, 'Other Persons Account');

        $tools = $this->captureTools($owner);
        $result = ($this->toolNamed($tools, 'get_account_balances')->handler)([]);

        $this->assertCount(1, $result);
        $this->assertSame('M-Pesa', $result[0]['account']);
    }

    public function test_calculate_wishlist_affordability_tool_finds_item_by_partial_name(): void
    {
        $user = User::factory()->create();
        $goal = $this->createSavingsGoal($user, 'Laptop', 7000000, monthlyContributionMinor: 350000);
        app(WishlistService::class)->createItem($user, 'MacBook Air M2', 7000000, linkedGoal: $goal);

        $tools = $this->captureTools($user);
        $result = ($this->toolNamed($tools, 'calculate_wishlist_affordability')->handler)(['item_name' => 'MacBook']);

        $this->assertSame('MacBook Air M2', $result['item']);
        $this->assertArrayHasKey('conservative_months', $result);
    }

    public function test_calculate_wishlist_affordability_tool_reports_a_clear_error_when_nothing_matches(): void
    {
        $user = User::factory()->create();

        $tools = $this->captureTools($user);
        $result = ($this->toolNamed($tools, 'calculate_wishlist_affordability')->handler)(['item_name' => 'Nonexistent Thing']);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_answer_question_returns_whatever_the_provider_says(): void
    {
        $user = User::factory()->create();

        $answer = app(FinancialAssistantService::class)->answerQuestion($user, 'How am I doing?');

        $this->assertSame('This is a fake AI response.', $answer);
    }
}
