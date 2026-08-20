<?php

namespace Tests\Feature\Achievements;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AchievementsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_lists_achievement_badges(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('achievements')
            ->assertSee('Achievements')
            ->assertSee('7-Day Streak')
            ->assertSee('Getting Things Done')
            ->assertSee('Goal Getter');
    }
}
