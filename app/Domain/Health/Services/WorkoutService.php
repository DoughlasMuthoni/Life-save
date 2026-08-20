<?php

namespace App\Domain\Health\Services;

use App\Domain\Health\Models\WorkoutEntry;
use App\Models\User;
use Carbon\CarbonInterface;

class WorkoutService
{
    public function recordEntry(User $user, CarbonInterface $performedAt, string $type, int $durationMinutes, ?string $notes = null): WorkoutEntry
    {
        return WorkoutEntry::create([
            'user_id' => $user->id,
            'performed_at' => $performedAt,
            'type' => $type,
            'duration_minutes' => $durationMinutes,
            'notes' => $notes,
        ]);
    }

    public function updateEntry(WorkoutEntry $entry, CarbonInterface $performedAt, string $type, int $durationMinutes, ?string $notes = null): WorkoutEntry
    {
        $entry->update([
            'performed_at' => $performedAt,
            'type' => $type,
            'duration_minutes' => $durationMinutes,
            'notes' => $notes,
        ]);

        return $entry;
    }

    public function deleteEntry(WorkoutEntry $entry): void
    {
        $entry->delete();
    }
}
