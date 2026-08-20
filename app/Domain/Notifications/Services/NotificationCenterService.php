<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Achievements\Services\AchievementService;
use App\Domain\Calendar\Models\CalendarEvent;
use App\Domain\Finance\Enums\ReconciliationStatus;
use App\Domain\Finance\Models\BalanceObservation;
use App\Domain\Finance\Services\FinancialReportingService;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Models\ProposedTransaction;
use App\Domain\Notifications\DataTransferObjects\Notification;
use App\Domain\Tasks\Enums\TaskStatus;
use App\Domain\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Aggregates "things worth telling the user about right now" from every
 * module that has any — no new table, no read/unread state (CLAUDE.md-
 * style: derive, don't persist, wherever the source data already answers
 * the question). Deliberately reuses the exact same counts the Dashboard's
 * "Needs attention" section computes, rather than a second, subtly
 * different implementation of the same queries.
 */
class NotificationCenterService
{
    public function __construct(
        private readonly FinancialReportingService $reports,
        private readonly AchievementService $achievements,
    ) {}

    /**
     * @return Collection<int, Notification>
     */
    public function getNotifications(User $user): Collection
    {
        return collect([
            ...$this->attentionNotifications($user),
            ...$this->todaysEventNotifications($user),
            ...$this->achievementNotifications($user),
        ]);
    }

    /**
     * @return Notification[]
     */
    private function attentionNotifications(User $user): array
    {
        $items = [];

        $unconfirmed = ProposedTransaction::where('user_id', $user->id)->where('status', ProposedTransactionStatus::PENDING_REVIEW)->count();
        if ($unconfirmed > 0) {
            $items[] = new Notification(
                key: 'unconfirmed-sms',
                title: "{$unconfirmed} unconfirmed SMS ".Str::plural('transaction', $unconfirmed),
                icon: 'chat',
                color: 'amber',
                url: route('finance.messages'),
            );
        }

        $mismatches = BalanceObservation::where('user_id', $user->id)->where('reconciliation_status', ReconciliationStatus::MISMATCHED)->count();
        if ($mismatches > 0) {
            $items[] = new Notification(
                key: 'reconciliation-mismatches',
                title: "{$mismatches} reconciliation ".Str::plural('mismatch', $mismatches),
                icon: 'scale',
                color: 'red',
                url: route('finance.reconciliation'),
            );
        }

        $overdueTasks = Task::where('user_id', $user->id)
            ->where('status', TaskStatus::PENDING)
            ->whereNotNull('due_date')
            ->where('due_date', '<', today())
            ->count();
        if ($overdueTasks > 0) {
            $items[] = new Notification(
                key: 'overdue-tasks',
                title: "{$overdueTasks} overdue ".Str::plural('task', $overdueTasks),
                icon: 'check-circle',
                color: 'red',
                url: route('tasks'),
            );
        }

        $behind = $this->reports->goalsBehindTarget($user);
        if ($behind->isNotEmpty()) {
            $items[] = new Notification(
                key: 'goals-behind',
                title: "{$behind->count()} savings ".Str::plural('goal', $behind->count()).' behind schedule',
                icon: 'flag',
                color: 'amber',
                url: route('savings-goals'),
            );
        }

        return $items;
    }

    /**
     * @return Notification[]
     */
    private function todaysEventNotifications(User $user): array
    {
        return CalendarEvent::query()
            ->where('user_id', $user->id)
            ->where('event_date', today())
            ->orderBy('event_time')
            ->get()
            ->map(fn (CalendarEvent $event) => new Notification(
                key: "calendar-event-{$event->id}",
                title: $event->title.($event->event_time ? ' at '.Carbon::parse($event->event_time)->format('g:i A') : ' today'),
                icon: 'calendar',
                color: $event->category?->color() ?? 'blue',
                url: route('calendar'),
            ))
            ->all();
    }

    /**
     * An achievement is surfaced only while its currentValue sits exactly
     * at its threshold — the moment right after crossing it. A count-based
     * achievement (tasks/goals/wishlist) naturally stops matching once the
     * count moves past the threshold; a streak-based one stops matching
     * the moment the streak grows past it or breaks. No timestamp, no
     * "seen it already" flag needed — this is a deliberate approximation,
     * not exact "just now" detection, but it self-clears without state.
     *
     * @return Notification[]
     */
    private function achievementNotifications(User $user): array
    {
        return $this->achievements->getAchievements($user)
            ->filter(fn ($achievement) => $achievement->unlocked && $achievement->currentValue === $achievement->targetValue)
            ->map(fn ($achievement) => new Notification(
                key: "achievement-{$achievement->key}",
                title: 'Achievement unlocked: '.$achievement->title,
                icon: 'trophy',
                color: 'amber',
                url: route('achievements'),
            ))
            ->values()
            ->all();
    }
}
