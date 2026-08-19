@props(['title' => null])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-slate-200 bg-white']) }}>
    @if ($title)
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
            <h2 class="text-sm font-semibold text-slate-700">{{ $title }}</h2>
            @isset($actions)
                <div>{{ $actions }}</div>
            @endisset
        </div>
    @endif
    {{ $slot }}
</div>
