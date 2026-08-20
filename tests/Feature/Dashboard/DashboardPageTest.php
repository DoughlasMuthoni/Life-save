<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Calendar\Models\CalendarEvent;
use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Services\TransactionService;
use App\Domain\Goals\Services\SavingsAllocationService;
use App\Domain\Ingestion\Services\FinancialMessageIngestionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class DashboardPageTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_the_dashboard_renders_with_no_data_yet(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('dashboard')
            ->assertSee('Add your first account');
    }

    public function test_the_dashboard_renders_kpis_and_recent_activity_once_there_is_data(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari', FinancialAccountProvider::MSHWARI);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);

        $transactions = app(TransactionService::class);
        $transactions->recordIncome($user, $mpesa, $salary, 5000000);
        $transactions->recordExpense($user, $mpesa, $groceries, 250000);
        $transactions->recordIncome($user, $mshwari, $salary, 1000000);

        Livewire::actingAs($user)
            ->test('dashboard')
            ->assertSee('Net Available Cash')
            ->assertSee('M-Pesa Balance')
            ->assertSee('Savings Total')
            ->assertSee('Recent transactions');
    }

    public function test_todays_calendar_events_appear_on_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user);
        CalendarEvent::create(['user_id' => $user->id, 'title' => 'Dentist today', 'event_date' => today()]);
        CalendarEvent::create(['user_id' => $user->id, 'title' => 'Next week thing', 'event_date' => today()->addWeek()]);

        Livewire::actingAs($user)
            ->test('dashboard')
            ->assertSee('Today')
            ->assertSee('Dentist today')
            ->assertDontSee('Next week thing');
    }

    public function test_no_today_section_when_nothing_is_scheduled(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user);

        Livewire::actingAs($user)
            ->test('dashboard')
            ->assertDontSee('Today');
    }

    public function test_the_dashboard_shows_attention_items_when_present(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);

        $raw = 'QGH7XI9K2L Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. '.
            'New M-PESA balance is Ksh3,450.00.';
        app(FinancialMessageIngestionService::class)->ingest($user, $raw);

        Livewire::actingAs($user)
            ->test('dashboard')
            ->assertSee('Needs attention')
            ->assertSee('unconfirmed SMS');
    }

    public function test_the_dashboard_shows_no_attention_section_when_nothing_needs_review(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user);

        Livewire::actingAs($user)->test('dashboard')->assertDontSee('Needs attention');
    }

    public function test_over_allocated_accounts_do_not_break_dashboard_rendering(): void
    {
        $user = User::factory()->create();
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari', FinancialAccountProvider::MSHWARI);
        $salary = $this->createIncomeCategory($user);
        $groceries = $this->createExpenseCategory($user);
        $goal = $this->createSavingsGoal($user);

        $transactions = app(TransactionService::class);
        $transactions->recordIncome($user, $mshwari, $salary, 5000000);
        app(SavingsAllocationService::class)->allocate($user, $goal, $mshwari, 4000000);
        $transactions->recordExpense($user, $mshwari, $groceries, 2000000);

        // An over-allocated account (negative net available cash) must
        // not throw — it renders, and shows the true (negative) figure.
        Livewire::actingAs($user)->test('dashboard')->assertSee('Net Available Cash');
    }
}
