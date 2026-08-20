<?php

namespace App\Domain\Calendar\Services;

use App\Domain\Calendar\Models\CalendarEvent;
use App\Models\User;
use Carbon\CarbonInterface;

class CalendarEventService
{
    public function createEvent(
        User $user,
        string $title,
        CarbonInterface $eventDate,
        ?string $eventTime = null,
        ?string $notes = null,
    ): CalendarEvent {
        return CalendarEvent::create([
            'user_id' => $user->id,
            'title' => $title,
            'event_date' => $eventDate,
            'event_time' => $eventTime,
            'notes' => $notes,
        ]);
    }

    public function updateEvent(
        CalendarEvent $event,
        string $title,
        CarbonInterface $eventDate,
        ?string $eventTime = null,
        ?string $notes = null,
    ): CalendarEvent {
        $event->update([
            'title' => $title,
            'event_date' => $eventDate,
            'event_time' => $eventTime,
            'notes' => $notes,
        ]);

        return $event;
    }

    public function deleteEvent(CalendarEvent $event): void
    {
        $event->delete();
    }
}
