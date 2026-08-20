<?php

use App\Domain\Achievements\Services\AchievementService;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public function getAchievementsProperty(AchievementService $achievements)
    {
        return $achievements->getAchievements(auth()->user())
            ->sortByDesc(fn ($a) => $a->unlocked ? 1 : 0)
            ->values();
    }
};
?>

<div>
    <x-ui.page-header
        title="Achievements"
        subtitle="Read-only badges, computed from what you've actually done — nothing here is manually awarded."
    />

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($this->achievements as $achievement)
            <div @class([
                'rounded-2xl border p-4',
                'border-amber-200 bg-amber-50/60' => $achievement->unlocked,
                'border-slate-200 bg-white' => ! $achievement->unlocked,
            ])>
                <div class="flex items-start gap-3">
                    <span @class([
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-full',
                        'bg-amber-100 text-amber-600' => $achievement->unlocked,
                        'bg-slate-100 text-slate-400' => ! $achievement->unlocked,
                    ])>
                        <x-icon :name="$achievement->icon" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-medium {{ $achievement->unlocked ? 'text-amber-900' : 'text-slate-500' }}">{{ $achievement->title }}</p>
                            @if ($achievement->unlocked)
                                <x-icon name="check-circle" class="h-4 w-4 shrink-0 text-amber-600" />
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">{{ $achievement->description }}</p>

                        @unless ($achievement->unlocked)
                            <div class="mt-2 flex items-center gap-2">
                                <div class="h-1.5 flex-1 rounded-full bg-slate-100">
                                    <div class="h-1.5 rounded-full bg-slate-400" style="width: {{ $achievement->progressPercent() }}%"></div>
                                </div>
                                <span class="shrink-0 text-xs text-slate-400">{{ $achievement->currentValue }}/{{ $achievement->targetValue }}</span>
                            </div>
                        @endunless
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
