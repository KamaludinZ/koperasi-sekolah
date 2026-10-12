{{-- Perubahan dibanding periode sebelumnya: ikon + teks (tidak bergantung warna saja). --}}
@props(['pct', 'previous' => null])
@if ($pct === null)
    <span {{ $attributes->class(['text-xs text-stone-500']) }}>— belum ada pembanding</span>
@else
    <span {{ $attributes->class(['text-xs']) }}>
        <span @class(['font-semibold',
                      'text-emerald-700' => $pct > 0, 'text-red-600' => $pct < 0, 'text-stone-500' => $pct === 0])>
            <span aria-hidden="true">{{ $pct > 0 ? '▲' : ($pct < 0 ? '▼' : '■') }}</span>
            <span class="sr-only">{{ $pct > 0 ? 'naik' : ($pct < 0 ? 'turun' : 'tetap') }}</span>
            {{ abs($pct) }}%
        </span>
        @if ($previous !== null)<span class="text-stone-500">dari {{ $previous }}</span>@endif
    </span>
@endif
