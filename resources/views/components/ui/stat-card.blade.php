@props(['icon' => 'info', 'label', 'trend' => null, 'trendUp' => true, 'color' => 'blue'])

@php
    $colors = [
        'blue' => 'bg-blue-50 text-blue-600',
        'green' => 'bg-green-50 text-green-600',
        'purple' => 'bg-purple-50 text-purple-600',
        'amber' => 'bg-amber-50 text-amber-600',
        'red' => 'bg-red-50 text-red-600',
        'slate' => 'bg-slate-100 text-slate-600',
    ];
    $colorClass = $colors[$color] ?? $colors['blue'];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-slate-200 bg-white p-5']) }}>
    <div class="flex items-center justify-between">
        <p class="text-sm text-slate-500">{{ $label }}</p>
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $colorClass }}">
            <x-icon :name="$icon" class="h-4.5 w-4.5" />
        </span>
    </div>
    <p class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $slot }}</p>
    @if ($trend !== null)
        <p class="mt-1 flex items-center gap-1 text-xs font-medium {{ $trendUp ? 'text-green-600' : 'text-red-600' }}">
            <x-icon :name="$trendUp ? 'trend-up' : 'trend-down'" class="h-3.5 w-3.5" />
            {{ $trend }}
        </p>
    @endif
</div>
