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
    <div class="min-h-screen bg-slate-50">
        <nav class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                <div class="flex items-center gap-6">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold text-slate-900">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-600 text-xs text-white">LO</span>
                        {{ config('app.name') }}
                    </a>
                    <div class="hidden items-center gap-4 text-sm font-medium text-slate-600 sm:flex">
                        <a href="{{ route('dashboard') }}" class="hover:text-slate-900 {{ request()->routeIs('dashboard') ? 'text-slate-900' : '' }}">Dashboard</a>
                        <a href="{{ route('finance.messages') }}" class="hover:text-slate-900 {{ request()->routeIs('finance.messages') ? 'text-slate-900' : '' }}">Messages</a>
                        <a href="{{ route('savings-goals') }}" class="hover:text-slate-900 {{ request()->routeIs('savings-goals') ? 'text-slate-900' : '' }}">Savings Goals</a>
                        <a href="{{ route('wishlist') }}" class="hover:text-slate-900 {{ request()->routeIs('wishlist') ? 'text-slate-900' : '' }}">Wishlist</a>
                        <a href="{{ route('shopping') }}" class="hover:text-slate-900 {{ request()->routeIs('shopping') ? 'text-slate-900' : '' }}">Shopping</a>
                        <a href="{{ route('tasks') }}" class="hover:text-slate-900 {{ request()->routeIs('tasks') ? 'text-slate-900' : '' }}">Tasks</a>
                        <a href="{{ route('reports.monthly') }}" class="hover:text-slate-900 {{ request()->routeIs('reports.*') ? 'text-slate-900' : '' }}">Reports</a>
                        <a href="{{ route('ai-assistant') }}" class="hover:text-slate-900 {{ request()->routeIs('ai-assistant') ? 'text-slate-900' : '' }}">AI Assistant</a>
                        <a href="{{ route('finance.accounts') }}" class="hover:text-slate-900 {{ request()->routeIs('finance.accounts') ? 'text-slate-900' : '' }}">Accounts</a>
                        <a href="{{ route('finance.categories') }}" class="hover:text-slate-900 {{ request()->routeIs('finance.categories') ? 'text-slate-900' : '' }}">Categories</a>
                        <a href="{{ route('finance.transactions') }}" class="hover:text-slate-900 {{ request()->routeIs('finance.transactions') ? 'text-slate-900' : '' }}">Transactions</a>
                        <a href="{{ route('finance.reconciliation') }}" class="hover:text-slate-900 {{ request()->routeIs('finance.reconciliation') ? 'text-slate-900' : '' }}">Reconciliation</a>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm font-medium text-slate-500 hover:text-slate-900">Sign out</button>
                </form>
            </div>
        </nav>

        <main class="mx-auto max-w-5xl px-4 py-8">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
