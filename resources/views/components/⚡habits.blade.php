<?php

use App\Domain\Habits\Models\Habit;
use App\Domain\Habits\Services\HabitService;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public function getHabitsProperty()
    {
        return Habit::query()
            ->where('user_id', auth()->id())
            // Enough history to compute a streak of any realistic length
            // without loading a habit's entire check-in history forever.
            ->with(['checkIns' => fn ($query) => $query->where('date', '>=', now()->subDays(120))])
            ->orderBy('name')
            ->get();
    }

    public function create(HabitService $habits): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']]);

        $habits->createHabit(auth()->user(), $this->name);

        $this->reset(['name']);
        $this->showForm = false;
    }

    public function toggleToday(int $habitId, HabitService $habits): void
    {
        $habit = Habit::where('user_id', auth()->id())->findOrFail($habitId);
        $habits->toggleCheckIn($habit, today());
    }

    public function delete(int $habitId, HabitService $habits): void
    {
        $habit = Habit::where('user_id', auth()->id())->findOrFail($habitId);
        $habits->deleteHabit($habit);
    }
};
?>

<div>
    <x-ui.page-header title="Habits" subtitle="A daily check-in and a streak — nothing more elaborate than that.">
        <x-slot:actions>
            <x-ui.button wire:click="$set('showForm', true)" variant="primary">
                <x-icon name="plus" class="h-4 w-4" /> New habit
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <form wire:submit="create" class="mt-6 flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="min-w-0 flex-1">
                <label class="block text-sm font-medium text-slate-700">Habit</label>
                <input wire:model="name" type="text" placeholder="e.g. Drink 2L of water" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-3">
                <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                <x-ui.button type="button" wire:click="$set('showForm', false)" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    @endif

    @if ($this->habits->isEmpty())
        <x-ui.empty-state icon="fire" title="No habits yet" description="Add one to start building a streak." class="mt-6" />
    @else
        <div class="mt-6 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
            @foreach ($this->habits as $habit)
                @php $checkedToday = $habit->isCheckedInOn(today()); @endphp
                <div class="flex items-center gap-3 px-5 py-3.5">
                    <button
                        wire:click="toggleToday({{ $habit->id }})"
                        aria-label="Toggle today's check-in"
                        @class([
                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 transition',
                            'border-green-500 bg-green-50 text-green-600' => $checkedToday,
                            'border-slate-300 text-transparent hover:border-blue-600 hover:bg-blue-50 hover:text-blue-600' => ! $checkedToday,
                        ])
                    >
                        <x-icon name="check" class="h-4 w-4" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-900">{{ $habit->name }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1 text-sm font-medium {{ $habit->currentStreak() > 0 ? 'text-amber-600' : 'text-slate-400' }}">
                        <x-icon name="fire" class="h-4 w-4" />
                        {{ $habit->currentStreak() }}
                    </div>
                    <button wire:click="delete({{ $habit->id }})" wire:confirm="Delete this habit and its check-in history?" class="shrink-0 text-xs text-slate-400 hover:text-red-600">
                        Delete
                    </button>
                </div>
            @endforeach
        </div>
    @endif
</div>
