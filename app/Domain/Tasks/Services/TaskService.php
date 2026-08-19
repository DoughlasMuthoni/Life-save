<?php

namespace App\Domain\Tasks\Services;

use App\Domain\Support\Enums\Priority;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;

class TaskService
{
    public function createTask(
        User $user,
        string $title,
        Priority $priority = Priority::MEDIUM,
        ?string $description = null,
        ?CarbonInterface $dueDate = null,
    ): Task {
        return Task::create([
            'user_id' => $user->id,
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'due_date' => $dueDate,
            'status' => TaskStatus::PENDING,
        ]);
    }

    public function markCompleted(Task $task): Task
    {
        $task->update([
            'status' => TaskStatus::COMPLETED,
            'completed_at' => now(),
        ]);

        return $task;
    }

    public function markCancelled(Task $task): Task
    {
        $task->update(['status' => TaskStatus::CANCELLED, 'completed_at' => null]);

        return $task;
    }

    public function reopen(Task $task): Task
    {
        $task->update(['status' => TaskStatus::PENDING, 'completed_at' => null]);

        return $task;
    }
}
