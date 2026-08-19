<?php

namespace Tests\Unit\Domain\Wishlist;

use App\Domain\Finance\Services\TransactionService;
use App\Domain\Goals\Services\SavingsAllocationService;
use App\Domain\Support\Enums\Priority;
use App\Domain\Wishlist\Enums\WishlistStatus;
use App\Domain\Wishlist\Services\WishlistService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class WishlistServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private WishlistService $wishlist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wishlist = app(WishlistService::class);
    }

    public function test_creating_an_item_defaults_to_considering(): void
    {
        $user = User::factory()->create();

        $item = $this->wishlist->createItem($user, 'MacBook Air M2', 16000000, Priority::HIGH);

        $this->assertSame(WishlistStatus::CONSIDERING, $item->status);
        $this->assertSame(16000000, $item->estimated_price_minor);
    }

    public function test_amount_allocated_reflects_the_linked_goals_allocation(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari');
        $salary = $this->createIncomeCategory($user);
        $goal = $this->createSavingsGoal($user, 'Laptop', 7000000);

        app(TransactionService::class)->recordIncome($user, $mshwari, $salary, 5000000);
        app(SavingsAllocationService::class)->allocate($user, $goal, $mshwari, 2850000);

        $item = $this->wishlist->createItem($user, 'MacBook Air M2', 7000000, linkedGoal: $goal);

        $this->assertSame(2850000, $item->amountAllocatedMinor());
        $this->assertSame(4150000, $item->remainingAmountMinor());
    }

    public function test_an_item_with_no_linked_goal_has_zero_allocated(): void
    {
        $user = User::factory()->create();

        $item = $this->wishlist->createItem($user, 'iPhone 15 Pro', 16500000);

        $this->assertSame(0, $item->amountAllocatedMinor());
        $this->assertSame(16500000, $item->remainingAmountMinor());
    }

    public function test_marking_purchased_sets_status_and_timestamp(): void
    {
        $user = User::factory()->create();
        $item = $this->wishlist->createItem($user, 'iPhone 15 Pro', 16500000);

        $this->wishlist->markPurchased($item);

        $this->assertSame(WishlistStatus::PURCHASED, $item->fresh()->status);
        $this->assertNotNull($item->fresh()->purchased_at);
    }

    public function test_set_status_cannot_be_used_to_mark_purchased(): void
    {
        $user = User::factory()->create();
        $item = $this->wishlist->createItem($user, 'iPhone 15 Pro', 16500000);

        $this->expectException(InvalidArgumentException::class);

        $this->wishlist->setStatus($item, WishlistStatus::PURCHASED);
    }

    public function test_linking_another_users_goal_is_rejected(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $goal = $this->createSavingsGoal($owner, 'Emergency Fund');

        $this->expectException(InvalidArgumentException::class);

        $this->wishlist->createItem($intruder, 'iPhone 15 Pro', 16500000, linkedGoal: $goal);
    }
}
