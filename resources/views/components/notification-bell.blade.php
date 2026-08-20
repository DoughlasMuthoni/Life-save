@props(['notifications'])

@php
    $notificationColorClasses = [
        'red' => 'bg-red-50 text-red-600',
        'amber' => 'bg-amber-50 text-amber-600',
        'blue' => 'bg-blue-50 text-blue-600',
        'purple' => 'bg-purple-50 text-purple-600',
        'slate' => 'bg-slate-100 text-slate-600',
    ];
@endphp

<div x-data="{ notifOpen: false }" class="relative">
    <button
        @click="notifOpen = !notifOpen"
        @click.outside="notifOpen = false"
        aria-label="Notifications"
        class="relative flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100"
    >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
        </svg>
        @if ($notifications->isNotEmpty())
            <span class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white">
                {{ $notifications->count() }}
            </span>
        @endif
    </button>

    <div
        x-show="notifOpen"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute right-0 z-50 mt-2 w-80 origin-top-right rounded-2xl border border-slate-200 bg-white shadow-lg"
        style="display: none;"
        @keydown.escape.window="notifOpen = false"
    >
        <div class="border-b border-slate-100 px-4 py-3">
            <p class="text-sm font-semibold text-slate-900">Notifications</p>
        </div>

        @if ($notifications->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-slate-400">Nothing needs your attention right now.</p>
        @else
            <div class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
                @foreach ($notifications as $notification)
                    <a href="{{ $notification->url }}" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $notificationColorClasses[$notification->color] ?? $notificationColorClasses['slate'] }}">
                            <x-icon :name="$notification->icon" class="h-4 w-4" />
                        </span>
                        <p class="text-sm text-slate-700">{{ $notification->title }}</p>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
