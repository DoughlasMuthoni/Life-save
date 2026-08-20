<?php

namespace App\Domain\Calendar\Enums;

enum CalendarEventCategory: string
{
    case BILL = 'bill';
    case APPOINTMENT = 'appointment';
    case BIRTHDAY = 'birthday';
    case REMINDER = 'reminder';
    case OTHER = 'other';

    /**
     * A stable color per category, shared by the month grid dots, the
     * agenda list badges, and the "add event" form — one place to change
     * the palette rather than three.
     */
    public function color(): string
    {
        return match ($this) {
            self::BILL => 'red',
            self::APPOINTMENT => 'blue',
            self::BIRTHDAY => 'purple',
            self::REMINDER => 'amber',
            self::OTHER => 'slate',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BILL => 'Bill',
            self::APPOINTMENT => 'Appointment',
            self::BIRTHDAY => 'Birthday',
            self::REMINDER => 'Reminder',
            self::OTHER => 'Other',
        };
    }
}
