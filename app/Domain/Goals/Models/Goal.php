<?php

namespace App\Domain\Goals\Models;

use App\Domain\Goals\Enums\AllocationEventType;
use App\Domain\Goals\Enums\GoalStatus;
use App\Domain\Goals\Enums\GoalType;
use App\Domain\Support\Enums\Priority;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'title', 'description', 'goal_type', 'target_value', 'unit', 'monthly_contribution_minor', 'target_date', 'status', 'priority', 'completed_at'])]
class Goal extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'goal_type' => GoalType::class,
            'status' => GoalStatus::class,
            'priority' => Priority::class,
            'target_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allocationEvents(): HasMany
    {
        return $this->hasMany(GoalAllocationEvent::class);
    }

    /**
     * A savings goal's allocated amount is always derived from its
     * allocation history, never a stored running total (CLAUDE.md §9,
     * same rule as account balances).
     */
    public function allocatedAmountMinor(): int
    {
        $allocated = $this->allocationEvents()->where('event_type', AllocationEventType::ALLOCATE)->sum('amount_minor');
        $released = $this->allocationEvents()->where('event_type', AllocationEventType::RELEASE)->sum('amount_minor');

        return (int) $allocated - (int) $released;
    }

    public function remainingAmountMinor(): int
    {
        return max(0, $this->target_value - $this->allocatedAmountMinor());
    }

    public function progressPercent(): float
    {
        if ($this->target_value <= 0) {
            return 0.0;
        }

        return min(100.0, round(($this->allocatedAmountMinor() / $this->target_value) * 100, 1));
    }

    /**
     * Months remaining at the goal's planned monthly contribution rate, or
     * null if that rate isn't set (nothing to project from) or the goal is
     * already fully allocated.
     */
    public function monthsRemaining(): ?int
    {
        $remaining = $this->remainingAmountMinor();

        if ($remaining <= 0) {
            return 0;
        }

        if ($this->monthly_contribution_minor === null || $this->monthly_contribution_minor <= 0) {
            return null;
        }

        return (int) ceil($remaining / $this->monthly_contribution_minor);
    }
}
