<?php

namespace Tests\Feature\Wishlist;

use App\Domain\Wishlist\Models\WishlistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class WishlistPageTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_a_user_can_add_a_wishlist_item_through_the_ui(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('wishlist')
            ->set('name', 'MacBook Air M2')
            ->set('estimatedPrice', '160000.00')
            ->set('priority', 'high')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('wishlist_items', [
            'user_id' => $user->id,
            'name' => 'MacBook Air M2',
            'estimated_price_minor' => 16000000,
            'status' => 'considering',
        ]);
    }

    public function test_a_user_can_mark_an_item_purchased(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('wishlist')
            ->set('name', 'iPhone 15 Pro')
            ->set('estimatedPrice', '165000.00')
            ->call('create');

        $item = WishlistItem::where('user_id', $user->id)->first();

        $component->call('markPurchased', $item->id)->assertHasNoErrors();

        $this->assertDatabaseHas('wishlist_items', ['id' => $item->id, 'status' => 'purchased']);
    }

    public function test_linking_a_goal_shows_affordability_scenarios(): void
    {
        $user = User::factory()->create();
        $goal = $this->createSavingsGoal($user, 'Laptop', 7000000, monthlyContributionMinor: 350000);

        Livewire::actingAs($user)
            ->test('wishlist')
            ->set('name', 'MacBook Air M2')
            ->set('estimatedPrice', '70000.00')
            ->set('linkedGoalId', (string) $goal->id)
            ->call('create')
            ->assertSee('Conservative')
            ->assertSee('Current Trend')
            ->assertSee('Aggressive');
    }

    public function test_an_unlinked_item_shows_no_affordability_scenarios(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('wishlist')
            ->set('name', 'Nike Air Force 1')
            ->set('estimatedPrice', '14500.00')
            ->call('create')
            ->assertDontSee('Conservative');
    }
}
