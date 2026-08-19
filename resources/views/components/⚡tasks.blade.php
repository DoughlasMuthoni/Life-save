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
    <x-ui.page-header title="Tasks" :subtitle="$this->pendingTasks->count().' open'">
        <x-slot:actions>
            <x-ui.button wire:click="$set('showForm', true)" variant="primary">
                <x-icon name="plus" class="h-4 w-4" /> New task
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <form wire:submit="create" class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Title</label>
                    <input wire:model="title" type="text" placeholder="e.g. Pay KPLC token" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Priority</label>
                    <select wire:model="priority" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Due date <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="dueDate" type="date" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Notes <span class="text-slate-400">(optional)</span></label>
                    <textarea wire:model="description" rows="3" placeholder="Any extra detail worth remembering&hellip;" class="mt-1 block w-full resize-y rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm leading-relaxed text-slate-800 placeholder:text-slate-400 shadow-sm transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none"></textarea>
                </div>
            </div>
            <div class="flex gap-3">
                <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                <x-ui.button type="button" wire:click="$set('showForm', false)" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    @endif

    @if ($this->pendingTasks->isEmpty())
        <x-ui.empty-state icon="check-circle" title="Nothing on your list" description="Add a task to get started." class="mt-6" />
    @else
        <div class="mt-6 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
            @foreach ($this->pendingTasks as $task)
                <div class="flex items-start gap-3 px-5 py-3.5">
                    <button
                        wire:click="complete({{ $task->id }})"
                        aria-label="Mark complete"
                        class="group mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-slate-300 text-transparent hover:border-blue-600 hover:bg-blue-50 hover:text-blue-600"
                    >
                        <x-icon name="check" class="h-3 w-3" />
                    </button>
                    <div class="flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm text-slate-900">{{ $task->title }}</p>
                            <x-ui.badge :color="['high' => 'red', 'medium' => 'amber', 'low' => 'slate'][$task->priority->value]">{{ ucfirst($task->priority->value) }}</x-ui.badge>
                            @if ($task->isOverdue())
                                <x-ui.badge color="red">Overdue</x-ui.badge>
                            @endif
                        </div>
                        @if ($task->description)
                            <p class="mt-0.5 text-xs text-slate-500">{{ $task->description }}</p>
                        @endif
                        @if ($task->due_date)
                            <p class="mt-0.5 flex items-center gap-1 text-xs text-slate-400">
                                <x-icon name="calendar" class="h-3 w-3" /> Due {{ $task->due_date->format('M j, Y') }}
                            </p>
                        @endif
                    </div>
                    <button wire:click="cancel({{ $task->id }})" wire:confirm="Cancel this task?" class="shrink-0 text-xs text-slate-400 hover:text-red-600">Cancel</button>
                </div>
            @endforeach
        </div>
    @endif

    @if ($this->completedTasks->isNotEmpty())
        <x-ui.section title="Recently closed" class="mt-8">
            <div class="divide-y divide-slate-100">
                @foreach ($this->completedTasks as $task)
                    <div class="flex items-center justify-between px-5 py-3">
                        <div class="flex items-center gap-2">
                            <x-icon name="check-circle" class="h-4 w-4 text-slate-300" />
                            <div>
                                <p class="text-sm text-slate-500 line-through">{{ $task->title }}</p>
                                <span class="text-xs text-slate-400">{{ ucfirst($task->status->value) }}</span>
                            </div>
                        </div>
                        @if ($task->status->value === 'completed')
                            <button wire:click="reopen({{ $task->id }})" class="text-xs font-medium text-blue-600 hover:text-blue-700">Reopen</button>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui.section>
    @endif
</div>
