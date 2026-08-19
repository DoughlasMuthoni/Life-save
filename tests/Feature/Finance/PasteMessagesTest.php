<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Ingestion\Models\ProposedTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class PasteMessagesTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private function sendMoneySms(): string
    {
        return 'QGH7XI9K2L Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. '.
            'New M-PESA balance is Ksh3,450.00. Transaction cost, Ksh0.00.';
    }

    public function test_pasting_an_sms_produces_a_reviewable_proposal(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);

        Livewire::actingAs($user)
            ->test('finance.messages.paste')
            ->set('pasteText', $this->sendMoneySms())
            ->call('parseMessages')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('financial_messages', ['user_id' => $user->id]);
        $this->assertDatabaseHas('proposed_transactions', ['user_id' => $user->id, 'status' => 'pending_review']);
    }

    public function test_confirming_a_proposal_from_the_page_posts_it_to_the_ledger(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $gifts = $this->createIncomeCategory($user, 'Gifts');

        $raw = 'QGH7RC00001 Confirmed. You have received Ksh2,400.00 from MARY WANJIKU 0718765432 on 31/5/25 at 5:07 PM. '.
            'New M-PESA balance is Ksh5,400.00.';

        $component = Livewire::actingAs($user)
            ->test('finance.messages.paste')
            ->set('pasteText', $raw)
            ->call('parseMessages');

        $proposalId = ProposedTransaction::where('user_id', $user->id)->first()->id;

        $component
            ->set("formData.{$proposalId}.transaction_category_id", (string) $gifts->id)
            ->call('confirmProposal', $proposalId)
            ->assertHasNoErrors();

        $this->assertSame(240000, $mpesa->fresh()->balanceMinor());
        $this->assertDatabaseHas('proposed_transactions', ['id' => $proposalId, 'status' => 'confirmed']);
    }

    public function test_rejecting_a_proposal_from_the_page_removes_it_from_the_queue(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);

        $component = Livewire::actingAs($user)
            ->test('finance.messages.paste')
            ->set('pasteText', $this->sendMoneySms())
            ->call('parseMessages');

        $proposalId = ProposedTransaction::where('user_id', $user->id)->first()->id;

        $component->call('rejectProposal', $proposalId)->assertHasNoErrors();

        $this->assertDatabaseHas('proposed_transactions', ['id' => $proposalId, 'status' => 'rejected']);
    }

    public function test_an_unrecognized_message_appears_in_needs_review_without_a_proposal(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('finance.messages.paste')
            ->set('pasteText', 'Your loan of Ksh5,000.00 has been approved.')
            ->call('parseMessages')
            ->assertSee('Unknown / Needs review')
            ->assertSee('Your loan of Ksh5,000.00');

        $this->assertDatabaseCount('proposed_transactions', 0);
    }

    public function test_pasting_the_same_message_twice_shows_it_under_possible_duplicates(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);

        Livewire::actingAs($user)->test('finance.messages.paste')->set('pasteText', $this->sendMoneySms())->call('parseMessages');

        Livewire::actingAs($user)
            ->test('finance.messages.paste')
            ->set('pasteText', $this->sendMoneySms())
            ->call('parseMessages')
            ->assertSee('Possible duplicates');
    }
}
