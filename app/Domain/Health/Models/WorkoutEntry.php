<?php

namespace App\Domain\Health\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'performed_at', 'type', 'duration_minutes', 'notes'])]
class WorkoutEntry extends Model
{
    protected function casts(): array
    {
        return [
            'performed_at' => 'date',
            'duration_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
