<?php

namespace Tests\Feature\Finance;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinancialAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_create_a_financial_account_through_the_ui(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('finance.accounts')
            ->set('name', 'M-Pesa')
            ->set('provider', FinancialAccountProvider::MPESA->value)
            ->set('currency', 'KES')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('financial_accounts', [
            'user_id' => $user->id,
            'name' => 'M-Pesa',
            'provider' => FinancialAccountProvider::MPESA->value,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'user_id' => $user->id,
            'action' => AuditAction::FINANCIAL_ACCOUNT_CREATED->value,
        ]);
    }

    public function test_the_account_form_requires_a_name_and_provider(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('finance.accounts')
            ->set('name', '')
            ->set('provider', '')
            ->call('create')
            ->assertHasErrors(['name', 'provider']);
    }

    public function test_the_accounts_page_only_shows_the_current_users_accounts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Livewire::actingAs($owner)
            ->test('finance.accounts')
            ->set('name', 'My M-Pesa')
            ->set('provider', FinancialAccountProvider::MPESA->value)
            ->call('create');

        Livewire::actingAs($other)
            ->test('finance.accounts')
            ->assertDontSee('My M-Pesa');
    }
}
