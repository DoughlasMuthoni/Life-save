<?php

namespace App\Domain\Calendar\Models;

use App\Domain\Calendar\Enums\CalendarEventCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'title', 'event_date', 'event_time', 'category', 'notes'])]
class CalendarEvent extends Model
{
    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'category' => CalendarEventCategory::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
