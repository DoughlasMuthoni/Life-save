<?php

namespace App\Domain\Habits\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'habit_id', 'date'])]
class HabitCheckIn extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
