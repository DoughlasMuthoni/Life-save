@props(['color' => 'slate'])

@php
    $colors = [
        'slate' => 'bg-slate-100 text-slate-600',
        'blue' => 'bg-blue-50 text-blue-700',
        'green' => 'bg-green-50 text-green-700',
        'amber' => 'bg-amber-50 text-amber-700',
        'red' => 'bg-red-50 text-red-700',
        'purple' => 'bg-purple-50 text-purple-700',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium '.($colors[$color] ?? $colors['slate'])]) }}>
    {{ $slot }}
</span>
