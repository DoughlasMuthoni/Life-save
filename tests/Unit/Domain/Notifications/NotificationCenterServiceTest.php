<?php

namespace Tests\Unit\Domain\Notifications;

use App\Domain\Calendar\Models\CalendarEvent;
use App\Domain\Finance\Enums\ReconciliationStatus;
use App\Domain\Finance\Models\BalanceObservation;
use App\Domain\Notifications\Services\NotificationCenterService;
use App\Domain\Support\Enums\Priority;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class NotificationCenterServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_a_user_with_no_activity_has_no_notifications(): void
    {
        $user = User::factory()->create();

        $notifications = app(NotificationCenterService::class)->getNotifications($user);

        $this->assertTrue($notifications->isEmpty());
    }

    public function test_an_overdue_task_produces_a_notification(): void
    {
        $user = User::factory()->create();
        Task::create([
            'user_id' => $user->id,
            'title' => 'Overdue thing',
            'priority' => Priority::MEDIUM,
            'due_date' => today()->subDays(2),
            'status' => TaskStatus::PENDING,
        ]);

        $notifications = app(NotificationCenterService::class)->getNotifications($user);

        $this->assertTrue($notifications->contains(fn ($n) => $n->key === 'overdue-tasks'));
    }

    public function test_a_reconciliation_mismatch_produces_a_notification(): void
    {
        $user = User::factory()->create();
        $account = $this->createFinancialAccount($user);
        BalanceObservation::create([
            'user_id' => $user->id,
            'financial_account_id' => $account->id,
            'observed_balance_minor' => 100000,
            'calculated_balance_minor' => 50000,
            'difference_minor' => 50000,
            'observed_at' => now(),
            'reconciliation_status' => ReconciliationStatus::MISMATCHED,
        ]);

        $notifications = app(NotificationCenterService::class)->getNotifications($user);

        $this->assertTrue($notifications->contains(fn ($n) => $n->key === 'reconciliation-mismatches'));
    }

    public function test_todays_calendar_event_produces_a_notification(): void
    {
        $user = User::factory()->create();
        $event = CalendarEvent::create(['user_id' => $user->id, 'title' => 'Dentist', 'event_date' => today()]);

        $notifications = app(NotificationCenterService::class)->getNotifications($user);

        $this->assertTrue($notifications->contains(fn ($n) => $n->key === "calendar-event-{$event->id}"));
    }

    public function test_a_future_calendar_event_does_not_produce_a_notification(): void
    {
        $user = User::factory()->create();
        CalendarEvent::create(['user_id' => $user->id, 'title' => 'Next week', 'event_date' => today()->addWeek()]);

        $notifications = app(NotificationCenterService::class)->getNotifications($user);

        $this->assertTrue($notifications->isEmpty());
    }

    public function test_crossing_a_task_completion_threshold_produces_an_achievement_notification(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            Task::create([
                'user_id' => $user->id,
                'title' => "Task {$i}",
                'priority' => Priority::MEDIUM,
                'status' => TaskStatus::COMPLETED,
                'completed_at' => now(),
            ]);
        }

        $notifications = app(NotificationCenterService::class)->getNotifications($user);

        $this->assertTrue($notifications->contains(fn ($n) => $n->key === 'achievement-tasks_10'));
    }

    public function test_an_achievement_well_past_its_threshold_does_not_reappear(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 15; $i++) {
            Task::create([
                'user_id' => $user->id,
                'title' => "Task {$i}",
                'priority' => Priority::MEDIUM,
                'status' => TaskStatus::COMPLETED,
                'completed_at' => now(),
            ]);
        }

        $notifications = app(NotificationCenterService::class)->getNotifications($user);

        $this->assertFalse($notifications->contains(fn ($n) => $n->key === 'achievement-tasks_10'));
    }
}
