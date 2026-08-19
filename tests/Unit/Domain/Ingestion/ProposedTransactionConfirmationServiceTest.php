<?php

namespace Tests\Unit\Domain\Ingestion;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Enums\JournalType;
use App\Domain\Finance\Exceptions\ImmutableLedgerRecordException;
use App\Domain\Finance\Services\TransactionService;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Services\FinancialMessageIngestionService;
use App\Domain\Ingestion\Services\ProposedTransactionConfirmationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class ProposedTransactionConfirmationServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private FinancialMessageIngestionService $ingestion;

    private ProposedTransactionConfirmationService $confirmation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestion = app(FinancialMessageIngestionService::class);
        $this->confirmation = app(ProposedTransactionConfirmationService::class);
    }

    private function receiveMoneySms(): string
    {
        return 'QGH7RC00001 Confirmed. You have received Ksh2,400.00 from MARY WANJIKU 0718765432 on 31/5/25 at 5:07 PM. '.
            'New M-PESA balance is Ksh5,400.00.';
    }

    private function paybillSms(): string
    {
        return 'QGH7PB00001 Confirmed. Ksh1,200.00 paid to KPLC PREPAID for account 987654321 on 31/5/25 at 7:15 PM. '.
            'New M-PESA balance is Ksh4,200.00. Transaction cost, Ksh22.00.';
    }

    private function withdrawalSms(): string
    {
        return 'QGH7WD00001 Confirmed. Ksh2,000.00 withdrawn from 123456 - AGENT NAME on 30/5/25 at 6:45 PM. '.
            'New M-PESA balance is Ksh1,800.00. Transaction cost, Ksh28.00.';
    }

    public function test_confirming_an_income_proposal_posts_it_to_the_ledger(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $salary = $this->createIncomeCategory($user, 'Gifts');

        $message = $this->ingestion->ingest($user, $this->receiveMoneySms());
        $proposed = $message->proposedTransaction;

        $journal = $this->confirmation->confirm($user, $proposed, [
            'transaction_category_id' => $salary->id,
        ]);

        $this->assertSame(JournalType::INCOME, $journal->journal_type);
        $this->assertSame(240000, $mpesa->fresh()->balanceMinor());

        $proposed->refresh();
        $this->assertSame(ProposedTransactionStatus::CONFIRMED, $proposed->status);
        $this->assertSame($journal->id, $proposed->journal_id);
        $this->assertSame('financial_message', $journal->source_type);
        $this->assertSame($message->id, $journal->source_id);
    }

    public function test_confirming_an_expense_with_a_fee_posts_three_balanced_legs(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $utilities = $this->createExpenseCategory($user, 'Utilities');
        $fees = $this->createExpenseCategory($user, 'Transaction Fees');

        app(TransactionService::class)->recordIncome(
            $user, $mpesa, $this->createIncomeCategory($user), 1000000
        );

        $message = $this->ingestion->ingest($user, $this->paybillSms());
        $proposed = $message->proposedTransaction;

        $journal = $this->confirmation->confirm($user, $proposed, [
            'transaction_category_id' => $utilities->id,
            'fee_category_id' => $fees->id,
        ]);

        $this->assertCount(3, $journal->entries);
        $this->assertSame(1000000 - 120000 - 2200, $mpesa->fresh()->balanceMinor());
        $this->assertSame(2200, $fees->ledgerAccount->balanceMinor());
    }

    public function test_confirming_a_withdrawal_posts_a_transfer_not_an_expense(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $cash = $this->createFinancialAccount($user, 'Cash', FinancialAccountProvider::CASH);
        $fees = $this->createExpenseCategory($user, 'Transaction Fees');

        app(TransactionService::class)->recordIncome(
            $user, $mpesa, $this->createIncomeCategory($user), 1000000
        );

        $message = $this->ingestion->ingest($user, $this->withdrawalSms());
        $proposed = $message->proposedTransaction;

        // Exactly one Cash account exists, so the ingestion service should
        // have pre-filled the destination already.
        $this->assertSame($cash->id, $proposed->destination_financial_account_id);

        $journal = $this->confirmation->confirm($user, $proposed, ['fee_category_id' => $fees->id]);

        $this->assertSame(JournalType::TRANSFER, $journal->journal_type);
        $this->assertSame(1000000 - 200000 - 2800, $mpesa->fresh()->balanceMinor());
        $this->assertSame(200000, $cash->fresh()->balanceMinor());
    }

    public function test_confirming_without_a_category_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);

        $message = $this->ingestion->ingest($user, $this->receiveMoneySms());

        $this->expectException(InvalidArgumentException::class);

        $this->confirmation->confirm($user, $message->proposedTransaction);
    }

    public function test_confirming_without_a_financial_account_is_rejected(): void
    {
        $user = User::factory()->create();
        $salary = $this->createIncomeCategory($user);

        // No M-Pesa account exists, so the ingestion service can't prefill one.
        $message = $this->ingestion->ingest($user, $this->receiveMoneySms());

        $this->expectException(InvalidArgumentException::class);

        $this->confirmation->confirm($user, $message->proposedTransaction, [
            'transaction_category_id' => $salary->id,
        ]);
    }

    public function test_a_proposal_cannot_be_confirmed_twice(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $salary = $this->createIncomeCategory($user);

        $message = $this->ingestion->ingest($user, $this->receiveMoneySms());
        $this->confirmation->confirm($user, $message->proposedTransaction, ['transaction_category_id' => $salary->id]);

        $this->expectException(RuntimeException::class);

        $this->confirmation->confirm($user, $message->proposedTransaction->fresh(), ['transaction_category_id' => $salary->id]);
    }

    public function test_rejecting_a_proposal_marks_it_rejected_and_freezes_it(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);

        $message = $this->ingestion->ingest($user, $this->receiveMoneySms());
        $proposed = $message->proposedTransaction;

        $this->confirmation->reject($user, $proposed);

        $proposed->refresh();
        $this->assertSame(ProposedTransactionStatus::REJECTED, $proposed->status);

        $this->expectException(ImmutableLedgerRecordException::class);
        $proposed->amount_minor = 1;
        $proposed->save();
    }

    public function test_a_user_cannot_confirm_another_users_proposal(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->createFinancialAccount($owner, 'M-Pesa', FinancialAccountProvider::MPESA);
        $salary = $this->createIncomeCategory($owner);

        $message = $this->ingestion->ingest($owner, $this->receiveMoneySms());

        $this->expectException(InvalidArgumentException::class);

        $this->confirmation->confirm($intruder, $message->proposedTransaction, ['transaction_category_id' => $salary->id]);
    }

    public function test_no_journal_is_posted_when_confirmation_fails_validation(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);

        $message = $this->ingestion->ingest($user, $this->receiveMoneySms());

        try {
            $this->confirmation->confirm($user, $message->proposedTransaction);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('journals', 0);
        $this->assertSame(ProposedTransactionStatus::PENDING_REVIEW, $message->proposedTransaction->fresh()->status);
    }
}
