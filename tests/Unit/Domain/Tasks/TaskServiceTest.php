<?php

namespace Tests\Unit\Domain\Tasks;

use App\Domain\Support\Enums\Priority;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Tasks\Services\TaskService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tasks = app(TaskService::class);
    }

    public function test_creating_a_task_defaults_to_pending(): void
    {
        $user = User::factory()->create();

        $task = $this->tasks->createTask($user, 'Pay KPLC token', Priority::HIGH);

        $this->assertSame(TaskStatus::PENDING, $task->status);
        $this->assertSame(Priority::HIGH, $task->priority);
    }

    public function test_marking_a_task_completed_sets_the_timestamp(): void
    {
        $user = User::factory()->create();
        $task = $this->tasks->createTask($user, 'Pay KPLC token');

        $this->tasks->markCompleted($task);

        $this->assertSame(TaskStatus::COMPLETED, $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_reopening_a_completed_task_clears_the_timestamp(): void
    {
        $user = User::factory()->create();
        $task = $this->tasks->createTask($user, 'Pay KPLC token');
        $this->tasks->markCompleted($task);

        $this->tasks->reopen($task);

        $this->assertSame(TaskStatus::PENDING, $task->fresh()->status);
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_a_pending_task_past_its_due_date_is_overdue(): void
    {
        $user = User::factory()->create();
        $task = $this->tasks->createTask($user, 'Pay rent', dueDate: Carbon::yesterday());

        $this->assertTrue($task->isOverdue());
    }

    public function test_a_completed_task_is_never_overdue(): void
    {
        $user = User::factory()->create();
        $task = $this->tasks->createTask($user, 'Pay rent', dueDate: Carbon::yesterday());
        $this->tasks->markCompleted($task);

        $this->assertFalse($task->fresh()->isOverdue());
    }

    public function test_a_task_without_a_due_date_is_never_overdue(): void
    {
        $user = User::factory()->create();
        $task = $this->tasks->createTask($user, 'Someday task');

        $this->assertFalse($task->isOverdue());
    }
}
