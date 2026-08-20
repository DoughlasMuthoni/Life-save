<?php

use App\Domain\Calendar\Enums\CalendarEventCategory;
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

    public string $category = '';

    public string $notes = '';

    /** The month currently shown in the grid, Y-m. */
    public string $viewMonth = '';

    public function mount(): void
    {
        $this->eventDate = today()->toDateString();
        $this->viewMonth = today()->format('Y-m');
    }

    /**
     * @return array<int, array<int, array{date: Carbon, inMonth: bool, isToday: bool, events: \Illuminate\Support\Collection}>>
     */
    public function getCalendarWeeksProperty(): array
    {
        $monthStart = Carbon::parse($this->viewMonth.'-01')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::MONDAY);

        $eventsByDate = CalendarEvent::query()
            ->where('user_id', auth()->id())
            ->whereBetween('event_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->orderBy('event_time')
            ->get()
            ->groupBy(fn (CalendarEvent $event) => $event->event_date->toDateString());

        $weeks = [];
        $cursor = $gridStart->copy();

        while ($cursor->lte($gridEnd)) {
            $week = [];

            for ($i = 0; $i < 7; $i++) {
                $week[] = [
                    'date' => $cursor->copy(),
                    'inMonth' => $cursor->month === $monthStart->month,
                    'isToday' => $cursor->isToday(),
                    'events' => $eventsByDate->get($cursor->toDateString(), collect()),
                ];
                $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return $weeks;
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

    public function previousMonth(): void
    {
        $this->viewMonth = Carbon::parse($this->viewMonth.'-01')->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->viewMonth = Carbon::parse($this->viewMonth.'-01')->addMonthNoOverflow()->format('Y-m');
    }

    public function goToToday(): void
    {
        $this->viewMonth = today()->format('Y-m');
    }

    public function startCreating(): void
    {
        $this->reset(['title', 'eventTime', 'category', 'notes', 'editingEventId']);
        $this->eventDate = today()->toDateString();
        $this->showForm = true;
    }

    public function startCreatingOn(string $date): void
    {
        $this->startCreating();
        $this->eventDate = $date;
    }

    public function startEditing(int $eventId): void
    {
        $event = CalendarEvent::where('user_id', auth()->id())->findOrFail($eventId);

        $this->editingEventId = $event->id;
        $this->title = $event->title;
        $this->eventDate = $event->event_date->toDateString();
        $this->eventTime = $event->event_time !== null ? substr($event->event_time, 0, 5) : '';
        $this->category = $event->category?->value ?? '';
        $this->notes = (string) $event->notes;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->reset(['title', 'eventTime', 'category', 'notes', 'editingEventId']);
        $this->eventDate = today()->toDateString();
    }

    public function save(CalendarEventService $events): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'eventDate' => ['required', 'date'],
            'eventTime' => ['nullable', 'date_format:H:i'],
            'category' => ['nullable', 'string', 'in:bill,appointment,birthday,reminder,other'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $date = Carbon::parse($this->eventDate);
        $time = $this->eventTime ?: null;
        $notes = $this->notes ?: null;
        $category = $this->category !== '' ? CalendarEventCategory::from($this->category) : null;

        if ($this->editingEventId !== null) {
            $event = CalendarEvent::where('user_id', auth()->id())->findOrFail($this->editingEventId);
            $events->updateEvent($event, $this->title, $date, $time, $notes, $category);
        } else {
            $events->createEvent(auth()->user(), $this->title, $date, $time, $notes, $category);
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
                    <label class="block text-sm font-medium text-slate-700">Category <span class="text-slate-400">(optional)</span></label>
                    <select wire:model="category" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="">Unspecified</option>
                        @foreach (CalendarEventCategory::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                    @error('category') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
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

    {{-- Month grid --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">{{ \Carbon\Carbon::parse($viewMonth.'-01')->format('F Y') }}</h2>
            <div class="flex items-center gap-1">
                <button wire:click="goToToday" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-500 hover:bg-slate-100">Today</button>
                <button wire:click="previousMonth" aria-label="Previous month" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100">
                    <x-icon name="chevron-left" class="h-4 w-4" />
                </button>
                <button wire:click="nextMonth" aria-label="Next month" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100">
                    <x-icon name="chevron-right" class="h-4 w-4" />
                </button>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-7 gap-px overflow-hidden rounded-lg bg-slate-200 text-center text-xs font-medium text-slate-400">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
                <div class="bg-slate-50 py-1.5">{{ $day }}</div>
            @endforeach
        </div>

        @php
            // Tailwind's build-time scanner needs complete, literal class
            // names — a dynamically-interpolated "bg-{$color}-50" would
            // never be found by it and would silently render unstyled in
            // production, so every category color is looked up from this
            // static table instead.
            $eventDotClasses = [
                'red' => 'bg-red-50 text-red-700',
                'blue' => 'bg-blue-50 text-blue-700',
                'purple' => 'bg-purple-50 text-purple-700',
                'amber' => 'bg-amber-50 text-amber-700',
                'slate' => 'bg-slate-100 text-slate-600',
            ];
        @endphp
        <div class="grid grid-cols-7 gap-px overflow-hidden rounded-b-lg bg-slate-200">
            @foreach ($this->calendarWeeks as $week)
                @foreach ($week as $day)
                    <button
                        wire:click="startCreatingOn('{{ $day['date']->toDateString() }}')"
                        @class([
                            'min-h-20 bg-white p-1.5 text-left align-top transition hover:bg-slate-50 sm:min-h-24',
                            'bg-slate-50/60 text-slate-300' => ! $day['inMonth'],
                        ])
                    >
                        <span @class([
                            'inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium',
                            'bg-blue-600 text-white' => $day['isToday'],
                            'text-slate-700' => ! $day['isToday'] && $day['inMonth'],
                            'text-slate-300' => ! $day['inMonth'],
                        ])>{{ $day['date']->day }}</span>

                        <div class="mt-1 space-y-0.5">
                            @foreach ($day['events']->take(3) as $event)
                                <div class="truncate rounded px-1 py-0.5 text-[11px] leading-tight {{ $eventDotClasses[$event->category?->color() ?? 'slate'] }}">{{ $event->title }}</div>
                            @endforeach
                            @if ($day['events']->count() > 3)
                                <p class="px-1 text-[11px] text-slate-400">+{{ $day['events']->count() - 3 }} more</p>
                            @endif
                        </div>
                    </button>
                @endforeach
            @endforeach
        </div>
    </div>

    {{-- Agenda list --}}
    @php
        $eventIconClasses = [
            'red' => 'bg-red-50 text-red-600',
            'blue' => 'bg-blue-50 text-blue-600',
            'purple' => 'bg-purple-50 text-purple-600',
            'amber' => 'bg-amber-50 text-amber-600',
            'slate' => 'bg-slate-100 text-slate-600',
        ];
    @endphp
    @if ($this->upcoming->isEmpty())
        <x-ui.empty-state icon="calendar" title="Nothing upcoming" description="Add an event to see it here." class="mt-6" />
    @else
        <div class="mt-6 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
            @foreach ($this->upcoming as $event)
                <div class="flex items-start gap-3 px-5 py-3.5">
                    <span class="flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-full {{ $eventIconClasses[$event->category?->color() ?? 'blue'] }}">
                        <x-icon name="calendar" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $event->title }}</p>
                            @if ($event->category)
                                <x-ui.badge :color="$event->category->color()">{{ $event->category->label() }}</x-ui.badge>
                            @endif
                        </div>
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
