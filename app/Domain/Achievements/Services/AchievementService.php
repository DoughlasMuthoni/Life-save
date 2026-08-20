<?php

namespace App\Domain\Achievements\Services;

use App\Domain\Achievements\DataTransferObjects\Achievement;
use App\Domain\Goals\Enums\GoalStatus;
use App\Domain\Goals\Models\Goal;
use App\Domain\Habits\Models\Habit;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Tasks\Models\Task;
use App\Domain\Wishlist\Enums\WishlistStatus;
use App\Domain\Wishlist\Models\WishlistItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A fixed, hand-written list of badges — deliberately not a generic rules
 * engine (CLAUDE.md's "don't over-abstract beyond what's needed"). Every
 * achievement is a pure read over data other modules already own; this
 * service writes nothing anywhere.
 */
class AchievementService
{
    /**
     * @return Collection<int, Achievement>
     */
    public function getAchievements(User $user): Collection
    {
        return collect([
            ...$this->habitAchievements($user),
            ...$this->taskAchievements($user),
            ...$this->goalAchievements($user),
            ...$this->wishlistAchievements($user),
        ]);
    }

    /**
     * @return Achievement[]
     */
    private function habitAchievements(User $user): array
    {
        $longestStreak = Habit::query()
            ->where('user_id', $user->id)
            ->with('checkIns')
            ->get()
            ->map(fn (Habit $habit) => $habit->currentStreak())
            ->max() ?? 0;

        return [
            new Achievement('habit_streak_7', '7-Day Streak', 'Keep any habit going for 7 days in a row.', 'fire', $longestStreak >= 7, $longestStreak, 7),
            new Achievement('habit_streak_30', '30-Day Streak', 'Keep any habit going for 30 days in a row.', 'fire', $longestStreak >= 30, $longestStreak, 30),
            new Achievement('habit_streak_100', '100-Day Streak', 'Keep any habit going for 100 days in a row.', 'fire', $longestStreak >= 100, $longestStreak, 100),
        ];
    }

    /**
     * @return Achievement[]
     */
    private function taskAchievements(User $user): array
    {
        $completed = Task::query()->where('user_id', $user->id)->where('status', TaskStatus::COMPLETED)->count();

        return [
            new Achievement('tasks_10', 'Getting Things Done', 'Complete 10 tasks.', 'check-circle', $completed >= 10, $completed, 10),
            new Achievement('tasks_50', 'Task Master', 'Complete 50 tasks.', 'check-circle', $completed >= 50, $completed, 50),
        ];
    }

    /**
     * @return Achievement[]
     */
    private function goalAchievements(User $user): array
    {
        $completed = Goal::query()->where('user_id', $user->id)->where('status', GoalStatus::COMPLETED)->count();

        return [
            new Achievement('goal_1', 'Goal Getter', 'Complete your first savings goal.', 'flag', $completed >= 1, $completed, 1),
            new Achievement('goal_5', 'Serial Saver', 'Complete 5 savings goals.', 'flag', $completed >= 5, $completed, 5),
        ];
    }

    /**
     * @return Achievement[]
     */
    private function wishlistAchievements(User $user): array
    {
        $purchased = WishlistItem::query()->where('user_id', $user->id)->where('status', WishlistStatus::PURCHASED)->count();

        return [
            new Achievement('wishlist_1', 'Treat Yourself', 'Mark your first wishlist item as purchased.', 'heart', $purchased >= 1, $purchased, 1),
            new Achievement('wishlist_5', 'Wishlist Warrior', 'Mark 5 wishlist items as purchased.', 'heart', $purchased >= 5, $purchased, 5),
        ];
    }
}
