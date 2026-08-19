@props(['padded' => true])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-slate-200 bg-white '.($padded ? 'p-5' : '')]) }}>
    {{ $slot }}
</div>
