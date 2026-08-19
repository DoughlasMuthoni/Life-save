<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\ReconciliationService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class ReconciliationPageTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_a_mismatch_appears_on_the_page_and_can_be_resolved(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);

        $observation = app(ReconciliationService::class)->recordObservation($user, $mpesa, 500000, Carbon::parse('2025-05-01'));

        Livewire::actingAs($user)
            ->test('finance.reconciliation')
            ->assertSee('Needs attention (1)')
            ->call('startResolving', $observation->id)
            ->set('resolutionNote', 'Turned out to be a missed cash deposit, added it manually.')
            ->call('confirmResolve')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('balance_observations', [
            'id' => $observation->id,
            'reconciliation_status' => 'resolved',
        ]);
    }

    public function test_the_page_requires_a_note_before_resolving(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);

        $observation = app(ReconciliationService::class)->recordObservation($user, $mpesa, 500000, Carbon::parse('2025-05-01'));

        Livewire::actingAs($user)
            ->test('finance.reconciliation')
            ->call('startResolving', $observation->id)
            ->set('resolutionNote', '')
            ->call('confirmResolve')
            ->assertHasErrors(['resolutionNote']);
    }

    public function test_a_matched_observation_does_not_appear_as_needing_attention(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);

        app(ReconciliationService::class)->recordObservation($user, $mpesa, 0, Carbon::parse('2025-05-01'));

        Livewire::actingAs($user)
            ->test('finance.reconciliation')
            ->assertSee('Needs attention (0)');
    }
}
