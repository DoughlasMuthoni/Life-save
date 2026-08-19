<?php

namespace Tests\Unit\Domain\Shopping;

use App\Domain\Finance\Services\TransactionService;
use App\Domain\Shopping\Models\Merchant;
use App\Domain\Shopping\Services\MerchantService;
use App\Domain\Shopping\Services\PurchaseService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class PurchaseServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private PurchaseService $purchases;

    private MerchantService $merchants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchases = app(PurchaseService::class);
        $this->merchants = app(MerchantService::class);
    }

    public function test_it_creates_a_purchase_with_a_merchant(): void
    {
        $user = User::factory()->create();
        $merchant = $this->merchants->findOrCreate($user, 'Quickmart Juja');

        $purchase = $this->purchases->createPurchase($user, 435000, Carbon::parse('2025-05-31'), merchant: $merchant);

        $this->assertSame(435000, $purchase->total_amount_minor);
        $this->assertSame('Quickmart Juja', $purchase->merchant->name);
    }

    public function test_merchant_find_or_create_is_idempotent_by_name(): void
    {
        $user = User::factory()->create();

        $first = $this->merchants->findOrCreate($user, 'Naivas');
        $second = $this->merchants->findOrCreate($user, 'Naivas');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Merchant::where('user_id', $user->id)->count());
    }

    public function test_it_adds_items_and_computes_line_totals(): void
    {
        $user = User::factory()->create();
        $purchase = $this->purchases->createPurchase($user, 435000, Carbon::parse('2025-05-31'));

        $this->purchases->addItem($purchase, 'Milk 500ml', 2, 8000);
        $this->purchases->addItem($purchase, 'Bread', 1, 12000);

        $purchase->refresh();

        $this->assertCount(2, $purchase->items);
        $this->assertSame(16000, $purchase->items->first()->lineTotalMinor());
        $this->assertSame(28000, $purchase->itemsTotalMinor());
    }

    public function test_items_reconciling_with_the_total_is_informational_not_enforced(): void
    {
        $user = User::factory()->create();
        $purchase = $this->purchases->createPurchase($user, 435000, Carbon::parse('2025-05-31'));

        $this->purchases->addItem($purchase, 'Milk', 1, 8000);
        $purchase->refresh();

        // Items (80.00) don't add up to the total (4,350.00) — a tip,
        // delivery fee, or simply an incomplete itemization. Not an error.
        $this->assertFalse($purchase->itemsReconcileWithTotal());
        $this->assertSame(435000, $purchase->fresh()->total_amount_minor);
    }

    public function test_linking_a_purchase_to_a_journal_connects_how_it_was_paid(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $groceries = $this->createExpenseCategory($user);
        $journal = app(TransactionService::class)->recordExpense($user, $mpesa, $groceries, 435000);

        $purchase = $this->purchases->createPurchase($user, 435000, Carbon::parse('2025-05-31'), journal: $journal);

        $this->assertSame($journal->id, $purchase->journal->id);
    }

    public function test_a_journal_cannot_back_two_purchases(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $groceries = $this->createExpenseCategory($user);
        $journal = app(TransactionService::class)->recordExpense($user, $mpesa, $groceries, 435000);

        $this->purchases->createPurchase($user, 435000, Carbon::parse('2025-05-31'), journal: $journal);

        $this->expectException(InvalidArgumentException::class);

        $this->purchases->createPurchase($user, 100000, Carbon::parse('2025-06-01'), journal: $journal);
    }

    public function test_a_user_cannot_link_another_users_journal(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $mpesa = $this->createFinancialAccount($owner);
        $groceries = $this->createExpenseCategory($owner);
        $journal = app(TransactionService::class)->recordExpense($owner, $mpesa, $groceries, 435000);

        $this->expectException(InvalidArgumentException::class);

        $this->purchases->createPurchase($intruder, 435000, Carbon::parse('2025-05-31'), journal: $journal);
    }

    public function test_link_to_journal_can_attach_a_transaction_after_the_fact(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $groceries = $this->createExpenseCategory($user);
        $journal = app(TransactionService::class)->recordExpense($user, $mpesa, $groceries, 435000);

        $purchase = $this->purchases->createPurchase($user, 435000, Carbon::parse('2025-05-31'));
        $this->assertNull($purchase->journal_id);

        $this->purchases->linkToJournal($user, $purchase, $journal);

        $this->assertSame($journal->id, $purchase->fresh()->journal_id);
    }
}
