<?php

namespace App\Domain\Goals\Services;

use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Goals\Enums\AllocationEventType;
use App\Domain\Goals\Models\Goal;
use App\Domain\Goals\Models\GoalAllocationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves money between "unallocated" and "earmarked for a goal" — never
 * moves real money anywhere (that's TransferService's job). A goal's
 * allocation is always scoped to a specific real financial_account, so
 * "how much of M-Shwari's balance is spoken for" is always answerable
 * (CLAUDE.md §SAVINGS: separate real accounts from virtual allocations,
 * never double-count).
 */
class SavingsAllocationService
{
    public function allocate(
        User $user,
        Goal $goal,
        FinancialAccount $account,
        int $amountMinor,
        ?string $note = null,
    ): GoalAllocationEvent {
        $this->assertOwnership($user, $goal, $account);

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Allocation amount must be positive.');
        }

        $unallocated = $this->unallocatedForAccount($account);

        if ($amountMinor > $unallocated) {
            throw new InvalidArgumentException(
                'Cannot allocate '.$amountMinor." — only {$unallocated} minor units of {$account->name} are unallocated."
            );
        }

        return GoalAllocationEvent::create([
            'user_id' => $user->id,
            'goal_id' => $goal->id,
            'financial_account_id' => $account->id,
            'event_type' => AllocationEventType::ALLOCATE,
            'amount_minor' => $amountMinor,
            'note' => $note,
        ]);
    }

    public function release(
        User $user,
        Goal $goal,
        FinancialAccount $account,
        int $amountMinor,
        ?string $note = null,
    ): GoalAllocationEvent {
        $this->assertOwnership($user, $goal, $account);

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Release amount must be positive.');
        }

        $allocatedFromThisAccount = $this->allocatedForGoalFromAccount($goal, $account);

        if ($amountMinor > $allocatedFromThisAccount) {
            throw new InvalidArgumentException(
                "Cannot release {$amountMinor} — only {$allocatedFromThisAccount} minor units are allocated to this goal from {$account->name}."
            );
        }

        return GoalAllocationEvent::create([
            'user_id' => $user->id,
            'goal_id' => $goal->id,
            'financial_account_id' => $account->id,
            'event_type' => AllocationEventType::RELEASE,
            'amount_minor' => $amountMinor,
            'note' => $note,
        ]);
    }

    /**
     * Re-earmarks money from one goal to another within the same real
     * account — implemented as a release from $fromGoal followed by an
     * allocate to $toGoal, both against $account, so it reuses exactly
     * the same validation and history as any other allocate/release
     * rather than a separate special-cased event type.
     *
     * @return array{release: GoalAllocationEvent, allocate: GoalAllocationEvent}
     */
    public function reallocate(
        User $user,
        Goal $fromGoal,
        Goal $toGoal,
        FinancialAccount $account,
        int $amountMinor,
        ?string $note = null,
    ): array {
        return DB::transaction(function () use ($user, $fromGoal, $toGoal, $account, $amountMinor, $note) {
            $release = $this->release($user, $fromGoal, $account, $amountMinor, $note ?? "Reallocated to \"{$toGoal->title}\"");
            $allocate = $this->allocate($user, $toGoal, $account, $amountMinor, $note ?? "Reallocated from \"{$fromGoal->title}\"");

            return ['release' => $release, 'allocate' => $allocate];
        });
    }

    /**
     * Total earmarked against this account, across every goal.
     */
    public function totalAllocatedForAccount(FinancialAccount $account): int
    {
        $allocated = GoalAllocationEvent::where('financial_account_id', $account->id)
            ->where('event_type', AllocationEventType::ALLOCATE)
            ->sum('amount_minor');

        $released = GoalAllocationEvent::where('financial_account_id', $account->id)
            ->where('event_type', AllocationEventType::RELEASE)
            ->sum('amount_minor');

        return (int) $allocated - (int) $released;
    }

    /**
     * Real balance minus what's earmarked. Negative means the account is
     * over-allocated — flagged, never auto-corrected (CLAUDE.md: "Do not
     * automatically decide which savings goal loses money").
     */
    public function unallocatedForAccount(FinancialAccount $account): int
    {
        return $account->balanceMinor() - $this->totalAllocatedForAccount($account);
    }

    public function isOverAllocated(FinancialAccount $account): bool
    {
        return $this->unallocatedForAccount($account) < 0;
    }

    private function allocatedForGoalFromAccount(Goal $goal, FinancialAccount $account): int
    {
        $allocated = GoalAllocationEvent::where('goal_id', $goal->id)
            ->where('financial_account_id', $account->id)
            ->where('event_type', AllocationEventType::ALLOCATE)
            ->sum('amount_minor');

        $released = GoalAllocationEvent::where('goal_id', $goal->id)
            ->where('financial_account_id', $account->id)
            ->where('event_type', AllocationEventType::RELEASE)
            ->sum('amount_minor');

        return (int) $allocated - (int) $released;
    }

    private function assertOwnership(User $user, Goal $goal, FinancialAccount $account): void
    {
        if ($goal->user_id !== $user->id) {
            throw new InvalidArgumentException('That goal does not belong to this user.');
        }

        if ($account->user_id !== $user->id) {
            throw new InvalidArgumentException('That financial account does not belong to this user.');
        }
    }
}
