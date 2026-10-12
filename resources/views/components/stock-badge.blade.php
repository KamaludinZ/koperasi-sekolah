@props(['stock', 'min', 'unit' => null])
@php
    $out = $stock <= 0;
    $low = ! $out && $stock <= $min;
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium tabular-nums',
    'bg-red-100 text-red-700' => $out,
    'bg-amber-100 text-amber-800' => $low,
    'bg-emerald-50 text-emerald-800' => ! $out && ! $low]) }}>
    @if ($out)
        Habis
    @else
        {{ $stock }}{{ $unit ? ' '.$unit : '' }}
        @if ($low)<span class="font-normal">· menipis</span>@endif
    @endif
</span>
