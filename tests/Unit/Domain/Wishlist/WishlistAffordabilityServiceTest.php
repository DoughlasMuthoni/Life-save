<?php

namespace Tests\Unit\Domain\Wishlist;

use App\Domain\Finance\Services\TransactionService;
use App\Domain\Goals\Services\SavingsAllocationService;
use App\Domain\Wishlist\Services\WishlistAffordabilityService;
use App\Domain\Wishlist\Services\WishlistService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class WishlistAffordabilityServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private WishlistAffordabilityService $affordability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->affordability = app(WishlistAffordabilityService::class);
    }

    public function test_it_returns_null_without_a_linked_goal(): void
    {
        $user = User::factory()->create();
        $item = app(WishlistService::class)->createItem($user, 'iPhone 15 Pro', 16500000);

        $this->assertNull($this->affordability->calculate($item));
    }

    public function test_it_returns_null_without_a_planned_monthly_contribution(): void
    {
        $user = User::factory()->create();
        $goal = $this->createSavingsGoal($user, 'Laptop', 7000000, monthlyContributionMinor: null);
        $item = app(WishlistService::class)->createItem($user, 'MacBook Air M2', 7000000, linkedGoal: $goal);

        $this->assertNull($this->affordability->calculate($item));
    }

    public function test_conservative_and_aggressive_scale_the_planned_contribution(): void
    {
        $user = User::factory()->create();
        // 3,500/month planned, 41,500 remaining after 28,500 allocated of a
        // 70,000 target: matches the mockup's Laptop goal numbers exactly.
        $goal = $this->createSavingsGoal($user, 'Laptop', 7000000, monthlyContributionMinor: 350000);
        $item = app(WishlistService::class)->createItem($user, 'MacBook Air M2', 7000000, linkedGoal: $goal);

        $mshwari = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);
        app(SavingsAllocationService::class)->allocate($user, $goal, $mshwari, 2850000);

        $scenarios = $this->affordability->calculate($item);

        $this->assertNotNull($scenarios);
        $this->assertSame(175000, $scenarios['conservative']['monthly_amount_minor']);
        $this->assertSame(525000, $scenarios['aggressive']['monthly_amount_minor']);

        $remaining = 7000000 - 2850000; // 4,150,000
        $this->assertSame((int) ceil($remaining / 175000), $scenarios['conservative']['months']);
        $this->assertSame((int) ceil($remaining / 525000), $scenarios['aggressive']['months']);
    }

    public function test_current_trend_reflects_actual_recent_allocations_not_just_the_plan(): void
    {
        $user = User::factory()->create();
        $goal = $this->createSavingsGoal($user, 'Laptop', 7000000, monthlyContributionMinor: 100000);
        $item = app(WishlistService::class)->createItem($user, 'MacBook Air M2', 7000000, linkedGoal: $goal);

        $mshwari = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);

        // Actual behavior has been much more generous than the plan.
        app(SavingsAllocationService::class)->allocate($user, $goal, $mshwari, 3000000);

        $scenarios = $this->affordability->calculate($item);

        // 3,000,000 net allocated within the 3-month lookback / 3 = 1,000,000/mo,
        // well above the planned 100,000/mo.
        $this->assertSame(1000000, $scenarios['current_trend']['monthly_amount_minor']);
    }

    public function test_an_already_affordable_item_shows_zero_months_remaining(): void
    {
        $user = User::factory()->create();
        $goal = $this->createSavingsGoal($user, 'Vacation', 1000000, monthlyContributionMinor: 250000);
        $item = app(WishlistService::class)->createItem($user, 'Weekend trip', 1000000, linkedGoal: $goal);

        $mshwari = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);
        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);
        app(SavingsAllocationService::class)->allocate($user, $goal, $mshwari, 1000000);

        $scenarios = $this->affordability->calculate($item);

        $this->assertSame(0, $scenarios['conservative']['months']);
        $this->assertSame(0, $scenarios['current_trend']['months']);
        $this->assertSame(0, $scenarios['aggressive']['months']);
    }
}
