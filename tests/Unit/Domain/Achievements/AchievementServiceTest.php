<?php

namespace Tests\Unit\Domain\Achievements;

use App\Domain\Achievements\Services\AchievementService;
use App\Domain\Goals\Enums\GoalStatus;
use App\Domain\Goals\Enums\GoalType;
use App\Domain\Goals\Models\Goal;
use App\Domain\Habits\Models\Habit;
use App\Domain\Habits\Models\HabitCheckIn;
use App\Domain\Support\Enums\Priority;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_seven_day_habit_streak_unlocks_the_seven_day_badge_but_not_thirty(): void
    {
        $user = User::factory()->create();
        $habit = Habit::create(['user_id' => $user->id, 'name' => 'Read']);

        for ($i = 0; $i < 7; $i++) {
            HabitCheckIn::create(['user_id' => $user->id, 'habit_id' => $habit->id, 'date' => today()->subDays($i)]);
        }

        $achievements = app(AchievementService::class)->getAchievements($user)->keyBy('key');

        $this->assertTrue($achievements['habit_streak_7']->unlocked);
        $this->assertFalse($achievements['habit_streak_30']->unlocked);
        $this->assertSame(7, $achievements['habit_streak_30']->currentValue);
    }

    public function test_completing_ten_tasks_unlocks_the_getting_things_done_badge(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            Task::create([
                'user_id' => $user->id,
                'title' => "Task {$i}",
                'status' => TaskStatus::COMPLETED,
                'priority' => Priority::MEDIUM,
                'completed_at' => now(),
            ]);
        }

        $achievements = app(AchievementService::class)->getAchievements($user)->keyBy('key');

        $this->assertTrue($achievements['tasks_10']->unlocked);
        $this->assertFalse($achievements['tasks_50']->unlocked);
    }

    public function test_a_completed_goal_unlocks_the_goal_getter_badge(): void
    {
        $user = User::factory()->create();

        Goal::create([
            'user_id' => $user->id,
            'title' => 'Emergency fund',
            'goal_type' => GoalType::SAVINGS,
            'target_value' => 10000000,
            'priority' => Priority::HIGH,
            'status' => GoalStatus::COMPLETED,
        ]);

        $achievements = app(AchievementService::class)->getAchievements($user)->keyBy('key');

        $this->assertTrue($achievements['goal_1']->unlocked);
    }

    public function test_a_user_with_no_activity_has_no_unlocked_achievements(): void
    {
        $user = User::factory()->create();

        $achievements = app(AchievementService::class)->getAchievements($user);

        $this->assertTrue($achievements->every(fn ($a) => ! $a->unlocked));
    }
}
