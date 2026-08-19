<?php

use App\Domain\Support\Enums\Priority;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Services\TaskService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public bool $showForm = false;

    public string $title = '';

    public string $description = '';

    public string $priority = 'medium';

    public string $dueDate = '';

    public function getPendingTasksProperty()
    {
        return Task::query()
            ->where('user_id', auth()->id())
            ->where('status', TaskStatus::PENDING)
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderBy('due_date')
            ->get();
    }

    public function getCompletedTasksProperty()
    {
        return Task::query()
            ->where('user_id', auth()->id())
            ->whereIn('status', [TaskStatus::COMPLETED, TaskStatus::CANCELLED])
            ->latest('updated_at')
            ->limit(10)
            ->get();
    }

    public function create(TaskService $tasks): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', 'string', 'in:low,medium,high'],
            'dueDate' => ['nullable', 'date'],
        ]);

        $tasks->createTask(
            user: auth()->user(),
            title: $this->title,
            priority: Priority::from($this->priority),
            description: $this->description ?: null,
            dueDate: $this->dueDate !== '' ? Carbon::parse($this->dueDate) : null,
        );

        $this->reset(['title', 'description', 'dueDate']);
        $this->priority = 'medium';
        $this->showForm = false;
    }

    public function complete(int $taskId, TaskService $tasks): void
    {
        $task = Task::where('user_id', auth()->id())->findOrFail($taskId);
        $tasks->markCompleted($task);
    }

    public function reopen(int $taskId, TaskService $tasks): void
    {
        $task = Task::where('user_id', auth()->id())->findOrFail($taskId);
        $tasks->reopen($task);
    }

    public function cancel(int $taskId, TaskService $tasks): void
    {
        $task = Task::where('user_id', auth()->id())->findOrFail($taskId);
        $tasks->markCancelled($task);
    }
};
?>

<div>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Tasks</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $this->pendingTasks->count() }} open</p>
        </div>
        <button wire:click="$set('showForm', true)" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            + New task
        </button>
    </div>

    @if ($showForm)
        <form wire:submit="create" class="mt-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Title</label>
                    <input wire:model="title" type="text" placeholder="e.g. Pay KPLC token" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Priority</label>
                    <select wire:model="priority" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Due date <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="dueDate" type="date" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Notes <span class="text-slate-400">(optional)</span></label>
                    <textarea wire:model="description" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm sm:text-sm"></textarea>
                </div>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Save</button>
                <button type="button" wire:click="$set('showForm', false)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
        @forelse ($this->pendingTasks as $task)
            <div class="flex items-start gap-3 px-4 py-3">
                <button wire:click="complete({{ $task->id }})" class="mt-0.5 h-5 w-5 shrink-0 rounded-full border-2 border-slate-300 hover:border-blue-600" aria-label="Mark complete"></button>
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <p class="text-sm text-slate-900">{{ $task->title }}</p>
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-red-50 text-red-700' => $task->priority->value === 'high',
                            'bg-amber-50 text-amber-700' => $task->priority->value === 'medium',
                            'bg-slate-100 text-slate-500' => $task->priority->value === 'low',
                        ])>{{ ucfirst($task->priority->value) }}</span>
                        @if ($task->isOverdue())
                            <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Overdue</span>
                        @endif
                    </div>
                    @if ($task->description)
                        <p class="mt-0.5 text-xs text-slate-500">{{ $task->description }}</p>
                    @endif
                    @if ($task->due_date)
                        <p class="mt-0.5 text-xs text-slate-400">Due {{ $task->due_date->format('M j, Y') }}</p>
                    @endif
                </div>
                <button wire:click="cancel({{ $task->id }})" wire:confirm="Cancel this task?" class="shrink-0 text-xs text-slate-400 hover:text-red-600">Cancel</button>
            </div>
        @empty
            <p class="px-4 py-8 text-center text-sm text-slate-500">Nothing on your list. Add a task to get started.</p>
        @endforelse
    </div>

    @if ($this->completedTasks->isNotEmpty())
        <div class="mt-8">
            <h2 class="text-sm font-semibold text-slate-700">Recently closed</h2>
            <div class="mt-3 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                @foreach ($this->completedTasks as $task)
                    <div class="flex items-center justify-between px-4 py-3">
                        <div>
                            <p class="text-sm text-slate-500 line-through">{{ $task->title }}</p>
                            <span class="text-xs text-slate-400">{{ ucfirst($task->status->value) }}</span>
                        </div>
                        @if ($task->status->value === 'completed')
                            <button wire:click="reopen({{ $task->id }})" class="text-xs font-medium text-blue-600 hover:text-blue-700">Reopen</button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
