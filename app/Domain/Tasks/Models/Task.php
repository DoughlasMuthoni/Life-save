<?php

namespace App\Domain\Tasks\Models;

use App\Domain\Support\Enums\Priority;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'title', 'description', 'priority', 'due_date', 'status', 'completed_at'])]
class Task extends Model
{
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'status' => TaskStatus::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === TaskStatus::PENDING
            && $this->due_date !== null
            && $this->due_date->isBefore(now()->startOfDay());
    }
}
