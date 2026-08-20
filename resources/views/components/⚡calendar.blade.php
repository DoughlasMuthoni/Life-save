<?php

use App\Domain\Calendar\Models\CalendarEvent;
use App\Domain\Calendar\Services\CalendarEventService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingEventId = null;

    public string $title = '';

    public string $eventDate = '';

    public string $eventTime = '';

    public string $notes = '';

    public function mount(): void
    {
        $this->eventDate = today()->toDateString();
    }

    public function getUpcomingProperty()
    {
        return CalendarEvent::query()
            ->where('user_id', auth()->id())
            ->where('event_date', '>=', today())
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->get();
    }

    public function getPastProperty()
    {
        return CalendarEvent::query()
            ->where('user_id', auth()->id())
            ->where('event_date', '<', today())
            ->orderByDesc('event_date')
            ->orderByDesc('event_time')
            ->limit(10)
            ->get();
    }

    public function startCreating(): void
    {
        $this->reset(['title', 'eventTime', 'notes', 'editingEventId']);
        $this->eventDate = today()->toDateString();
        $this->showForm = true;
    }

    public function startEditing(int $eventId): void
    {
        $event = CalendarEvent::where('user_id', auth()->id())->findOrFail($eventId);

        $this->editingEventId = $event->id;
        $this->title = $event->title;
        $this->eventDate = $event->event_date->toDateString();
        $this->eventTime = $event->event_time !== null ? substr($event->event_time, 0, 5) : '';
        $this->notes = (string) $event->notes;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->reset(['title', 'eventTime', 'notes', 'editingEventId']);
        $this->eventDate = today()->toDateString();
    }

    public function save(CalendarEventService $events): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'eventDate' => ['required', 'date'],
            'eventTime' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $date = Carbon::parse($this->eventDate);
        $time = $this->eventTime ?: null;
        $notes = $this->notes ?: null;

        if ($this->editingEventId !== null) {
            $event = CalendarEvent::where('user_id', auth()->id())->findOrFail($this->editingEventId);
            $events->updateEvent($event, $this->title, $date, $time, $notes);
        } else {
            $events->createEvent(auth()->user(), $this->title, $date, $time, $notes);
        }

        $this->cancel();
    }

    public function delete(int $eventId, CalendarEventService $events): void
    {
        $event = CalendarEvent::where('user_id', auth()->id())->findOrFail($eventId);
        $events->deleteEvent($event);
    }
};
?>

<div>
    <x-ui.page-header title="Calendar" subtitle="Dated events — a plain list, nothing recurring or synced.">
        <x-slot:actions>
            <x-ui.button wire:click="startCreating" variant="primary">
                <x-icon name="plus" class="h-4 w-4" /> New event
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <form wire:submit="save" class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Title</label>
                    <input wire:model="title" type="text" placeholder="e.g. Dentist appointment" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Date</label>
                    <input wire:model="eventDate" type="date" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('eventDate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Time <span class="text-slate-400">(optional)</span></label>
                    <input wire:model="eventTime" type="time" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    @error('eventTime') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-slate-700">Notes <span class="text-slate-400">(optional)</span></label>
                    <textarea wire:model="notes" rows="2" class="mt-1 block w-full resize-y rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm leading-relaxed text-slate-800 placeholder:text-slate-400 shadow-sm transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none"></textarea>
                </div>
            </div>
            <div class="flex gap-3">
                <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                <x-ui.button type="button" wire:click="cancel" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    @endif

    @if ($this->upcoming->isEmpty())
        <x-ui.empty-state icon="calendar" title="Nothing upcoming" description="Add an event to see it here." class="mt-6" />
    @else
        <div class="mt-6 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
            @foreach ($this->upcoming as $event)
                <div class="flex items-start gap-3 px-5 py-3.5">
                    <span class="flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-full bg-blue-50 text-blue-600">
                        <x-icon name="calendar" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-900">{{ $event->title }}</p>
                        <p class="text-xs text-slate-500">
                            {{ $event->event_date->isToday() ? 'Today' : $event->event_date->format('D, M j, Y') }}
                            @if ($event->event_time)
                                &middot; {{ \Carbon\Carbon::parse($event->event_time)->format('g:i A') }}
                            @endif
                        </p>
                        @if ($event->notes)
                            <p class="mt-1 break-words text-sm text-slate-600">{{ $event->notes }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <button wire:click="startEditing({{ $event->id }})" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Edit event">
                            <x-icon name="pencil" class="h-4 w-4" />
                        </button>
                        <button wire:click="delete({{ $event->id }})" wire:confirm="Delete this event?" class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Delete event">
                            <x-icon name="x-mark" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($this->past->isNotEmpty())
        <x-ui.section title="Past events" class="mt-8">
            <div class="divide-y divide-slate-100">
                @foreach ($this->past as $event)
                    <div class="flex items-center justify-between px-5 py-3">
                        <p class="text-sm text-slate-500">{{ $event->title }}</p>
                        <p class="text-xs text-slate-400">{{ $event->event_date->format('M j, Y') }}</p>
                    </div>
                @endforeach
            </div>
        </x-ui.section>
    @endif
</div>
