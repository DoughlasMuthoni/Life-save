<?php

namespace App\Domain\Health\Services;

use App\Domain\Health\Enums\MealType;
use App\Domain\Health\Models\MealEntry;
use App\Models\User;
use Carbon\CarbonInterface;

class MealService
{
    public function recordEntry(User $user, CarbonInterface $eatenAt, string $description, ?MealType $mealType = null): MealEntry
    {
        return MealEntry::create([
            'user_id' => $user->id,
            'eaten_at' => $eatenAt,
            'meal_type' => $mealType,
            'description' => $description,
        ]);
    }

    public function updateEntry(MealEntry $entry, CarbonInterface $eatenAt, string $description, ?MealType $mealType = null): MealEntry
    {
        $entry->update([
            'eaten_at' => $eatenAt,
            'meal_type' => $mealType,
            'description' => $description,
        ]);

        return $entry;
    }

    public function deleteEntry(MealEntry $entry): void
    {
        $entry->delete();
    }
}
