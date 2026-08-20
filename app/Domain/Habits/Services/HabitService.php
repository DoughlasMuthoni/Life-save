<?php

namespace App\Domain\Habits\Services;

use App\Domain\Habits\Models\Habit;
use App\Domain\Habits\Models\HabitCheckIn;
use App\Models\User;
use Carbon\CarbonInterface;

class HabitService
{
    public function createHabit(User $user, string $name): Habit
    {
        return Habit::create([
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }

    public function deleteHabit(Habit $habit): void
    {
        $habit->delete();
    }

    /**
     * Checks a habit in for the given day if it isn't already, or removes
     * that day's check-in if it is — a single tap toggles today's box on
     * the habit list, matching the UI's checkbox-style interaction.
     */
    public function toggleCheckIn(Habit $habit, CarbonInterface $date): void
    {
        $existing = HabitCheckIn::where('habit_id', $habit->id)->whereDate('date', $date)->first();

        if ($existing !== null) {
            $existing->delete();

            return;
        }

        HabitCheckIn::create([
            'user_id' => $habit->user_id,
            'habit_id' => $habit->id,
            'date' => $date,
        ]);
    }
}
