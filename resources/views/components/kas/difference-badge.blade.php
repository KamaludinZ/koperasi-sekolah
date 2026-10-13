{{--
    Badge selisih kas: Pas / Kurang RpX / Lebih RpX / Belum dihitung / Berjalan.
    Status dibedakan dengan label + ikon + warna (tidak hanya warna).
    - amount  : selisih (dihitung − seharusnya); null = belum dihitung
    - running : shift masih berjalan (selisih belum ada)
    - nominal : tampilkan nominal pada kurang/lebih (bawaan ya)
    - size    : 'sm' (bawaan, tabel) | 'md'
--}}
@props(['amount' => null, 'running' => false, 'nominal' => true, 'size' => 'sm'])
@php
    $amount = $amount === null ? null : (int) $amount;
    [$label, $tone, $icon, $srText] = match (true) {
        $running => ['Berjalan', 'bg-stone-100 text-stone-600', 'M12 8v4l3 2M12 21a9 9 0 100-18 9 9 0 000 18z', 'Shift masih berjalan'],
        $amount === null => ['Belum dihitung', 'bg-stone-100 text-stone-600', 'M12 8v4l3 2M12 21a9 9 0 100-18 9 9 0 000 18z', 'Uang laci belum dihitung'],
        $amount === 0 => ['Pas', 'bg-emerald-100 text-emerald-800', 'm5 13 4 4L19 7', 'Kas pas, tanpa selisih'],
        $amount < 0 => ['Kurang', 'bg-red-100 text-red-800', 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z', 'Kas kurang'],
        default => ['Lebih', 'bg-amber-100 text-amber-900', 'M12 9v4m0 4h.01M12 21a9 9 0 100-18 9 9 0 000 18z', 'Kas lebih'],
    };
    $money = ! $running && $amount ? 'Rp'.number_format(abs($amount), 0, ',', '.') : null;
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full font-semibold whitespace-nowrap', $tone,
    'px-2 py-0.5 text-xs' => $size === 'sm', 'px-2.5 py-1 text-sm' => $size === 'md']) }}
      title="{{ $srText }}{{ $money ? ' '.$money : '' }}">
    <svg @class(['shrink-0', 'size-3.5' => $size === 'sm', 'size-4' => $size === 'md']) fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg>
    {{ $label }}@if ($money && $nominal) <span class="tabular-nums">{{ $money }}</span>@endif
</span>
