<?php

use App\Domain\Health\Enums\MealType;
use App\Domain\Health\Models\MealEntry;
use App\Domain\Health\Services\MealService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingEntryId = null;

    public string $eatenAt = '';

    public string $mealType = '';

    public string $description = '';

    public function mount(): void
    {
        $this->eatenAt = now()->format('Y-m-d\TH:i');
    }

    public function getEntriesProperty()
    {
        return MealEntry::query()->where('user_id', auth()->id())->orderByDesc('eaten_at')->get();
    }

    public function startCreating(): void
    {
        $this->reset(['mealType', 'description', 'editingEntryId']);
        $this->eatenAt = now()->format('Y-m-d\TH:i');
        $this->showForm = true;
    }

    public function startEditing(int $entryId): void
    {
        $entry = MealEntry::where('user_id', auth()->id())->findOrFail($entryId);

        $this->editingEntryId = $entry->id;
        $this->eatenAt = $entry->eaten_at->format('Y-m-d\TH:i');
        $this->mealType = $entry->meal_type?->value ?? '';
        $this->description = $entry->description;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->reset(['mealType', 'description', 'editingEntryId']);
        $this->eatenAt = now()->format('Y-m-d\TH:i');
    }

    public function save(MealService $meals): void
    {
        $this->validate([
            'eatenAt' => ['required', 'date'],
            'mealType' => ['nullable', 'string', 'in:breakfast,lunch,dinner,snack'],
            'description' => ['required', 'string', 'max:1000'],
        ]);

        $dateTime = Carbon::parse($this->eatenAt);
        $type = $this->mealType !== '' ? MealType::from($this->mealType) : null;

        if ($this->editingEntryId !== null) {
            $entry = MealEntry::where('user_id', auth()->id())->findOrFail($this->editingEntryId);
            $meals->updateEntry($entry, $dateTime, $this->description, $type);
        } else {
            $meals->recordEntry(auth()->user(), $dateTime, $this->description, $type);
        }

        $this->cancel();
    }

    public function delete(int $entryId, MealService $meals): void
    {
        $entry = MealEntry::where('user_id', auth()->id())->findOrFail($entryId);
        $meals->deleteEntry($entry);
    }
};
?>

<div>
    <x-ui.page-header title="Meals" subtitle="A basic food log — no calorie or macro tracking.">
        <x-slot:actions>
            <x-ui.button wire:click="startCreating" variant="primary">
                <x-icon name="plus" class="h-4 w-4" /> New meal
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <form wire:submit="save" class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-slate-700">When</label>
                    <input wire:model="eatenAt" type="datetime-local" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('eatenAt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Meal <span class="text-slate-400">(optional)</span></label>
                    <select wire:model="mealType" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="">Unspecified</option>
                        <option value="breakfast">Breakfast</option>
                        <option value="lunch">Lunch</option>
                        <option value="dinner">Dinner</option>
                        <option value="snack">Snack</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">What did you eat?</label>
                    <textarea wire:model="description" rows="2" placeholder="e.g. Grilled chicken, rice, steamed vegetables" class="mt-1 block w-full resize-y rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm leading-relaxed text-slate-800 placeholder:text-slate-400 shadow-sm transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none"></textarea>
                    @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex gap-3">
                <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                <x-ui.button type="button" wire:click="cancel" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    @endif

    @if ($this->entries->isEmpty())
        <x-ui.empty-state icon="bag" title="No meals logged yet" description="Log your first meal to start tracking." class="mt-6" />
    @else
        <div class="mt-6 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
            @foreach ($this->entries as $entry)
                <div class="flex items-start gap-3 px-5 py-3.5">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-green-50 text-green-600">
                        <x-icon name="bag" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($entry->meal_type)
                                <x-ui.badge color="green">{{ ucfirst($entry->meal_type->value) }}</x-ui.badge>
                            @endif
                            <span class="text-xs text-slate-500">{{ $entry->eaten_at->format('D, M j, Y \a\t g:i A') }}</span>
                        </div>
                        <p class="mt-1 break-words text-sm text-slate-700">{{ $entry->description }}</p>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <button wire:click="startEditing({{ $entry->id }})" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Edit meal">
                            <x-icon name="pencil" class="h-4 w-4" />
                        </button>
                        <button wire:click="delete({{ $entry->id }})" wire:confirm="Delete this meal?" class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Delete meal">
                            <x-icon name="x-mark" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
