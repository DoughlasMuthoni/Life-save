<?php

use App\Domain\Health\Models\WorkoutEntry;
use App\Domain\Health\Services\WorkoutService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingEntryId = null;

    public string $performedAt = '';

    public string $type = '';

    public string $durationMinutes = '';

    public string $notes = '';

    public function mount(): void
    {
        $this->performedAt = today()->toDateString();
    }

    public function getEntriesProperty()
    {
        return WorkoutEntry::query()->where('user_id', auth()->id())->orderByDesc('performed_at')->orderByDesc('id')->get();
    }

    public function startCreating(): void
    {
        $this->reset(['type', 'durationMinutes', 'notes', 'editingEntryId']);
        $this->performedAt = today()->toDateString();
        $this->showForm = true;
    }

    public function startEditing(int $entryId): void
    {
        $entry = WorkoutEntry::where('user_id', auth()->id())->findOrFail($entryId);

        $this->editingEntryId = $entry->id;
        $this->performedAt = $entry->performed_at->toDateString();
        $this->type = $entry->type;
        $this->durationMinutes = (string) $entry->duration_minutes;
        $this->notes = (string) $entry->notes;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->reset(['type', 'durationMinutes', 'notes', 'editingEntryId']);
        $this->performedAt = today()->toDateString();
    }

    public function save(WorkoutService $workouts): void
    {
        $this->validate([
            'performedAt' => ['required', 'date'],
            'type' => ['required', 'string', 'max:255'],
            'durationMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $date = Carbon::parse($this->performedAt);
        $notes = $this->notes ?: null;

        if ($this->editingEntryId !== null) {
            $entry = WorkoutEntry::where('user_id', auth()->id())->findOrFail($this->editingEntryId);
            $workouts->updateEntry($entry, $date, $this->type, (int) $this->durationMinutes, $notes);
        } else {
            $workouts->recordEntry(auth()->user(), $date, $this->type, (int) $this->durationMinutes, $notes);
        }

        $this->cancel();
    }

    public function delete(int $entryId, WorkoutService $workouts): void
    {
        $entry = WorkoutEntry::where('user_id', auth()->id())->findOrFail($entryId);
        $workouts->deleteEntry($entry);
    }
};
?>

<div>
    <x-ui.page-header title="Workouts" subtitle="A log of exercise sessions — type, duration, notes.">
        <x-slot:actions>
            <x-ui.button wire:click="startCreating" variant="primary">
                <x-icon name="plus" class="h-4 w-4" /> New workout
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <form wire:submit="save" class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Date</label>
                    <input wire:model="performedAt" type="date" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('performedAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Type</label>
                    <input wire:model="type" type="text" placeholder="e.g. Running, Gym, Yoga" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Duration (minutes)</label>
                    <input wire:model="durationMinutes" type="number" min="1" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('durationMinutes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Notes <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="notes" type="text" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
            </div>
            <div class="flex gap-3">
                <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                <x-ui.button type="button" wire:click="cancel" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    @endif

    @if ($this->entries->isEmpty())
        <x-ui.empty-state icon="fire" title="No workouts logged yet" description="Log your first session to start tracking." class="mt-6" />
    @else
        <div class="mt-6 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
            @foreach ($this->entries as $entry)
                <div class="flex items-center gap-3 px-5 py-3.5">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-orange-50 text-orange-600">
                        <x-icon name="fire" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-medium text-slate-900">{{ $entry->type }}</p>
                            <x-ui.badge color="amber">{{ $entry->duration_minutes }} min</x-ui.badge>
                        </div>
                        <p class="text-xs text-slate-500">{{ $entry->performed_at->format('D, M j, Y') }}</p>
                        @if ($entry->notes)
                            <p class="mt-1 text-sm text-slate-600">{{ $entry->notes }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <button wire:click="startEditing({{ $entry->id }})" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Edit workout">
                            <x-icon name="pencil" class="h-4 w-4" />
                        </button>
                        <button wire:click="delete({{ $entry->id }})" wire:confirm="Delete this workout?" class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Delete workout">
                            <x-icon name="x-mark" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
