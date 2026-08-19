<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#155DFC">
    <title>{{ $title ?? config('app.name') }}</title>

    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans antialiased text-slate-900">
    @php
        $navGroups = [
            null => [
                ['dashboard', 'home', 'Dashboard'],
            ],
            'Finance' => [
                ['finance.messages', 'chat', 'Messages'],
                ['finance.accounts', 'wallet', 'Accounts'],
                ['finance.categories', 'tag', 'Categories'],
                ['finance.transactions', 'list', 'Transactions'],
                ['finance.reconciliation', 'scale', 'Reconciliation'],
            ],
            'Planning' => [
                ['savings-goals', 'flag', 'Savings Goals'],
                ['wishlist', 'heart', 'Wishlist'],
                ['shopping', 'bag', 'Shopping'],
                ['tasks', 'check-circle', 'Tasks'],
            ],
            'Insights' => [
                ['reports.monthly', 'chart', 'Reports'],
                ['ai-assistant', 'sparkles', 'AI Assistant'],
            ],
        ];
    @endphp

    <div x-data="{ sidebarOpen: false }" class="min-h-screen">
        <div
            x-show="sidebarOpen"
            x-transition.opacity
            @click="sidebarOpen = false"
            class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"
            style="display: none;"
        ></div>

        <aside
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-slate-200 bg-white transition-transform duration-200 ease-in-out lg:translate-x-0"
        >
            <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-slate-200 px-5">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-xs font-semibold text-white">LO</span>
                <span class="truncate font-semibold text-slate-900">{{ config('app.name') }}</span>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
                @foreach ($navGroups as $group => $items)
                    @if ($group)
                        <p class="mt-5 mb-1 px-3 text-xs font-semibold tracking-wider text-slate-400 uppercase first:mt-0">{{ $group }}</p>
                    @endif
                    @foreach ($items as [$routeName, $icon, $label])
                        @php
                            $active = str_ends_with($routeName, '.monthly')
                                ? request()->routeIs('reports.*')
                                : request()->routeIs($routeName);
                        @endphp
                        <a
                            href="{{ route($routeName) }}"
                            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium {{ $active ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}"
                        >
                            <x-icon :name="$icon" class="h-5 w-5 shrink-0 {{ $active ? 'text-blue-600' : 'text-slate-400' }}" />
                            {{ $label }}
                        </a>
                    @endforeach
                @endforeach
            </nav>

            <div class="shrink-0 border-t border-slate-200 p-3">
                <div class="flex items-center gap-2.5 rounded-lg px-2 py-1.5">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-600">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-900">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" title="Sign out" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-red-600">
                            <x-icon name="logout" class="h-4.5 w-4.5" />
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="lg:pl-64">
            <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur lg:hidden">
                <button @click="sidebarOpen = true" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100">
                    <x-icon name="menu" class="h-5 w-5" />
                </button>
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-xs font-semibold text-white">LO</span>
                <span class="font-semibold text-slate-900">{{ config('app.name') }}</span>
            </header>

            <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
