<?php

namespace App\Domain\Health\Services;

use App\Domain\Health\Models\WeightEntry;
use App\Models\User;
use Carbon\CarbonInterface;

class WeightService
{
    public function recordEntry(User $user, CarbonInterface $recordedAt, string $weightKg, ?string $notes = null): WeightEntry
    {
        return WeightEntry::create([
            'user_id' => $user->id,
            'recorded_at' => $recordedAt,
            'weight_kg' => $weightKg,
            'notes' => $notes,
        ]);
    }

    public function updateEntry(WeightEntry $entry, CarbonInterface $recordedAt, string $weightKg, ?string $notes = null): WeightEntry
    {
        $entry->update([
            'recorded_at' => $recordedAt,
            'weight_kg' => $weightKg,
            'notes' => $notes,
        ]);

        return $entry;
    }

    public function deleteEntry(WeightEntry $entry): void
    {
        $entry->delete();
    }
}
