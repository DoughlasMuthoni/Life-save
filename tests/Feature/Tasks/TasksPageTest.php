<?php

namespace Tests\Feature\Tasks;

use App\Domain\Tasks\Services\TaskService;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TasksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_create_a_task_through_the_ui(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('tasks')
            ->set('title', 'Pay KPLC token')
            ->set('priority', 'high')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('Pay KPLC token')
            ->assertSee('High');

        $this->assertDatabaseHas('tasks', ['user_id' => $user->id, 'title' => 'Pay KPLC token', 'status' => 'pending']);
    }

    public function test_a_user_can_complete_a_task(): void
    {
        $user = User::factory()->create();
        $task = app(TaskService::class)->createTask($user, 'Pay rent');

        Livewire::actingAs($user)
            ->test('tasks')
            ->call('complete', $task->id)
            ->assertHasNoErrors();

        $this->assertSame('completed', $task->fresh()->status->value);
    }

    public function test_a_user_can_reopen_a_completed_task(): void
    {
        $user = User::factory()->create();
        $task = app(TaskService::class)->createTask($user, 'Pay rent');
        app(TaskService::class)->markCompleted($task);

        Livewire::actingAs($user)
            ->test('tasks')
            ->call('reopen', $task->id);

        $this->assertSame('pending', $task->fresh()->status->value);
    }

    public function test_a_user_cannot_act_on_another_users_task(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $task = app(TaskService::class)->createTask($owner, 'Private task');

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($intruder)
            ->test('tasks')
            ->call('complete', $task->id);
    }

    public function test_an_overdue_task_is_flagged(): void
    {
        $user = User::factory()->create();
        app(TaskService::class)->createTask($user, 'Late task', dueDate: now()->subDay());

        Livewire::actingAs($user)->test('tasks')->assertSee('Overdue');
    }
}
