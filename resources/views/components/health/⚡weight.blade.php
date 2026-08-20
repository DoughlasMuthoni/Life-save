<?php

use App\Domain\Health\Models\WeightEntry;
use App\Domain\Health\Services\WeightService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingEntryId = null;

    public string $recordedAt = '';

    public string $weightKg = '';

    public string $notes = '';

    public function mount(): void
    {
        $this->recordedAt = today()->toDateString();
    }

    public function getEntriesProperty()
    {
        return WeightEntry::query()->where('user_id', auth()->id())->orderByDesc('recorded_at')->get();
    }

    public function startCreating(): void
    {
        $this->reset(['weightKg', 'notes', 'editingEntryId']);
        $this->recordedAt = today()->toDateString();
        $this->showForm = true;
    }

    public function startEditing(int $entryId): void
    {
        $entry = WeightEntry::where('user_id', auth()->id())->findOrFail($entryId);

        $this->editingEntryId = $entry->id;
        $this->recordedAt = $entry->recorded_at->toDateString();
        $this->weightKg = (string) $entry->weight_kg;
        $this->notes = (string) $entry->notes;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->reset(['weightKg', 'notes', 'editingEntryId']);
        $this->recordedAt = today()->toDateString();
    }

    public function save(WeightService $weights): void
    {
        $this->validate([
            'recordedAt' => ['required', 'date'],
            'weightKg' => ['required', 'numeric', 'min:1', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $date = Carbon::parse($this->recordedAt);
        $notes = $this->notes ?: null;

        if ($this->editingEntryId !== null) {
            $entry = WeightEntry::where('user_id', auth()->id())->findOrFail($this->editingEntryId);
            $weights->updateEntry($entry, $date, $this->weightKg, $notes);
        } else {
            $weights->recordEntry(auth()->user(), $date, $this->weightKg, $notes);
        }

        $this->cancel();
    }

    public function delete(int $entryId, WeightService $weights): void
    {
        $entry = WeightEntry::where('user_id', auth()->id())->findOrFail($entryId);
        $weights->deleteEntry($entry);
    }
};
?>

<div>
    <x-ui.page-header title="Weight" subtitle="A simple log of weight entries over time.">
        <x-slot:actions>
            <x-ui.button wire:click="startCreating" variant="primary">
                <x-icon name="plus" class="h-4 w-4" /> New entry
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <form wire:submit="save" class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Date</label>
                    <input wire:model="recordedAt" type="date" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('recordedAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Weight (kg)</label>
                    <input wire:model="weightKg" type="text" inputmode="decimal" placeholder="72.5" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('weightKg') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
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
        <x-ui.empty-state icon="scale" title="No weight entries yet" description="Log your first entry to start tracking." class="mt-6" />
    @else
        <div class="mt-6 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
            @foreach ($this->entries as $i => $entry)
                @php
                    $previous = $this->entries->get($i + 1);
                    $delta = $previous ? round($entry->weight_kg - $previous->weight_kg, 2) : null;
                @endphp
                <div class="flex items-center gap-3 px-5 py-3.5">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-purple-50 text-purple-600">
                        <x-icon name="scale" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-semibold text-slate-900">{{ $entry->weight_kg }} kg</p>
                            @if ($delta !== null && $delta != 0)
                                <span class="flex items-center gap-0.5 text-xs font-medium {{ $delta < 0 ? 'text-green-600' : 'text-amber-600' }}">
                                    <x-icon :name="$delta < 0 ? 'trend-down' : 'trend-up'" class="h-3 w-3" />
                                    {{ $delta > 0 ? '+' : '' }}{{ $delta }} kg
                                </span>
                            @endif
                        </div>
                        <p class="text-xs text-slate-500">{{ $entry->recorded_at->format('D, M j, Y') }}</p>
                        @if ($entry->notes)
                            <p class="mt-1 text-sm text-slate-600">{{ $entry->notes }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <button wire:click="startEditing({{ $entry->id }})" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Edit entry">
                            <x-icon name="pencil" class="h-4 w-4" />
                        </button>
                        <button wire:click="delete({{ $entry->id }})" wire:confirm="Delete this entry?" class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Delete entry">
                            <x-icon name="x-mark" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
