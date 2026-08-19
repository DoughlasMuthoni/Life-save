<?php

namespace App\Domain\Goals\Services;

use App\Domain\Goals\Enums\GoalStatus;
use App\Domain\Goals\Enums\GoalType;
use App\Domain\Goals\Models\Goal;
use App\Domain\Support\Enums\Priority;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Plain goal CRUD — the interesting behavior (allocation, progress) lives
 * in SavingsAllocationService and on the Goal model itself.
 */
class GoalService
{
    public function createSavingsGoal(
        User $user,
        string $title,
        int $targetValueMinor,
        Priority $priority = Priority::MEDIUM,
        ?string $description = null,
        ?int $monthlyContributionMinor = null,
        ?CarbonInterface $targetDate = null,
    ): Goal {
        return Goal::create([
            'user_id' => $user->id,
            'title' => $title,
            'description' => $description,
            'goal_type' => GoalType::SAVINGS,
            'target_value' => $targetValueMinor,
            'unit' => 'KES_MINOR',
            'monthly_contribution_minor' => $monthlyContributionMinor,
            'target_date' => $targetDate,
            'status' => GoalStatus::ACTIVE,
            'priority' => $priority,
        ]);
    }

    public function markCompleted(Goal $goal): Goal
    {
        $goal->update([
            'status' => GoalStatus::COMPLETED,
            'completed_at' => now(),
        ]);

        return $goal;
    }

    public function abandon(Goal $goal): Goal
    {
        $goal->update(['status' => GoalStatus::ABANDONED]);

        return $goal;
    }
}
