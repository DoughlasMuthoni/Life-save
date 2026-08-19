@props(['icon' => 'info', 'title', 'description' => null])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center']) }}>
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
        <x-icon :name="$icon" class="h-6 w-6" />
    </div>
    <p class="mt-3 text-sm font-medium text-slate-700">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
    @endif
    @isset($actions)
        <div class="mt-4 flex flex-wrap justify-center gap-3">{{ $actions }}</div>
    @endisset
</div>
