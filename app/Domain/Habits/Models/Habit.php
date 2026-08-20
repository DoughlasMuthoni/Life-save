<?php

namespace App\Domain\Habits\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name'])]
class Habit extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(HabitCheckIn::class);
    }

    public function isCheckedInOn(CarbonInterface $date): bool
    {
        return $this->checkIns->contains(fn (HabitCheckIn $checkIn) => $checkIn->date->isSameDay($date));
    }

    /**
     * Consecutive checked-in days ending today. A streak survives until
     * midnight without a check-in — if today isn't checked in yet, the
     * count still looks back from yesterday rather than resetting to
     * zero the moment the day starts.
     */
    public function currentStreak(): int
    {
        $checkedDates = $this->checkIns->pluck('date')->map(fn (CarbonInterface $date) => $date->toDateString())->flip();

        $cursor = today();

        if (! isset($checkedDates[$cursor->toDateString()])) {
            $cursor = $cursor->copy()->subDay();
        }

        $streak = 0;

        while (isset($checkedDates[$cursor->toDateString()])) {
            $streak++;
            $cursor = $cursor->copy()->subDay();
        }

        return $streak;
    }
}
