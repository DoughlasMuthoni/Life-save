<?php

namespace Tests\Feature;

use App\Domain\Support\Enums\Priority;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Like the help panel, the bell lives in the shared layout — a real HTTP
 * request is needed to see it (Livewire::test()'s component-only harness
 * never wraps output in its #[Layout(...)]).
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bell_renders_with_no_badge_when_there_is_nothing_to_report(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Nothing needs your attention right now.', false);
    }

    public function test_the_bell_shows_an_overdue_task_notification(): void
    {
        $user = User::factory()->create();
        Task::create([
            'user_id' => $user->id,
            'title' => 'Overdue thing',
            'priority' => Priority::MEDIUM,
            'due_date' => today()->subDays(2),
            'status' => TaskStatus::PENDING,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('1 overdue task');
    }
}
