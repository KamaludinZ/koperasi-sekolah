{{-- Logo lengkap: lambang + nama koperasi + nama madrasah. variant: light (latar gelap) | dark (latar terang). --}}
@props(['variant' => 'light', 'size' => 'md'])
@php
    $profile = \App\Support\MockData::profile();
    $light = $variant === 'light';
@endphp
<span {{ $attributes->class('flex items-center gap-3') }}>
    @if (! empty($profile['logo']))
        <img src="{{ $profile['logo'] }}" alt="" @class(['shrink-0 rounded-lg object-cover', 'size-10' => $size === 'md', 'size-14' => $size === 'lg', 'size-8' => $size === 'sm'])>
    @else
        <x-brand.mark @class(['shrink-0', 'size-10' => $size === 'md', 'size-14' => $size === 'lg', 'size-8' => $size === 'sm']) />
    @endif
    <span class="min-w-0 leading-tight">
        <span @class(['block truncate font-display font-bold', 'text-lg' => $size !== 'lg', 'text-2xl' => $size === 'lg',
                      'text-white' => $light, 'text-emerald-950' => ! $light])>{{ $profile['short_name'] }}</span>
        <span @class(['block truncate text-xs', 'text-amber-300' => $light, 'text-emerald-700' => ! $light])>{{ $profile['school'] }}</span>
    </span>
</span>
