<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerEntrySide;
use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use App\Domain\Ingestion\Services\FinancialMessageIngestionService;
use App\Domain\Ingestion\Services\ProposedTransactionConfirmationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class FulizaIngestionTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private function fulizaSms(): string
    {
        return 'UHIJK33R8O Confirmed. Fuliza M-PESA amount is Ksh 20.00. Access Fee charged Ksh 0.20. '.
            'Total Fuliza M-PESA outstanding amount is Ksh585.38 due on 15/09/26. '.
            'To check daily charges, Dial *334#OK Select Query Charges';
    }

    public function test_a_fuliza_message_is_parsed_deterministically_as_a_transfer_shaped_proposal(): void
    {
        $user = User::factory()->create();

        $message = app(FinancialMessageIngestionService::class)->ingest($user, $this->fulizaSms());

        $this->assertSame('App\Domain\Ingestion\Parsers\MpesaParser', $message->parser_type);

        $proposal = $message->proposedTransaction;
        $this->assertNotNull($proposal);
        $this->assertSame(ExtractedTransactionType::FULIZA_DRAWDOWN, $proposal->transaction_type);
        $this->assertSame(2000, $proposal->amount_minor);
        $this->assertSame(20, $proposal->fee_minor);
        $this->assertSame(58538, $proposal->reported_balance_minor);
    }

    public function test_account_guessing_is_reversed_for_fuliza_liability_is_source_mpesa_is_destination(): void
    {
        $user = User::factory()->create();
        $fuliza = $this->createFinancialAccount($user, 'Fuliza', FinancialAccountProvider::FULIZA, type: LedgerAccountType::LIABILITY);
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);

        $message = app(FinancialMessageIngestionService::class)->ingest($user, $this->fulizaSms());
        $proposal = $message->proposedTransaction;

        $this->assertSame($fuliza->id, $proposal->financial_account_id);
        $this->assertSame($mpesa->id, $proposal->destination_financial_account_id);
    }

    public function test_confirming_a_fuliza_drawdown_correctly_increases_the_liability_and_the_mpesa_balance(): void
    {
        $user = User::factory()->create();
        $fuliza = $this->createFinancialAccount($user, 'Fuliza', FinancialAccountProvider::FULIZA, type: LedgerAccountType::LIABILITY);
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $fees = $this->createExpenseCategory($user, 'Fuliza Fees');

        $message = app(FinancialMessageIngestionService::class)->ingest($user, $this->fulizaSms());
        $proposal = $message->proposedTransaction;

        $journal = app(ProposedTransactionConfirmationService::class)->confirm($user, $proposal, [
            'fee_category_id' => $fees->id,
        ]);

        // Fuliza (liability) now owes 2000 + 20 = 2020 minor units.
        $this->assertSame(2020, $fuliza->fresh()->balanceMinor());
        // M-Pesa (asset) received the 2000 minor units the drawdown covered.
        $this->assertSame(2000, $mpesa->fresh()->balanceMinor());
        // The access fee posted as a real expense, not silently folded in.
        $this->assertSame(20, $fees->fresh()->ledgerAccount->balanceMinor());

        $entries = $journal->entries;
        $this->assertSame(3, $entries->count());
        $fulizaEntry = $entries->firstWhere('ledger_account_id', $fuliza->ledger_account_id);
        $this->assertSame(LedgerEntrySide::CREDIT, $fulizaEntry->side);
        $this->assertSame(2020, $fulizaEntry->amount_minor);
    }

    public function test_a_liability_account_can_be_created_and_shows_up_as_a_liability(): void
    {
        $user = User::factory()->create();
        $fuliza = $this->createFinancialAccount($user, 'Fuliza', FinancialAccountProvider::FULIZA, type: LedgerAccountType::LIABILITY);

        $this->assertSame(LedgerAccountType::LIABILITY, $fuliza->ledgerAccount->type);
        $this->assertSame(0, $fuliza->balanceMinor());
    }
}
