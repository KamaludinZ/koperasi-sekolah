@props(['status'])
@php
    $tone = ['menunggu' => 'bg-amber-100 text-amber-900', 'diproses' => 'bg-sky-100 text-sky-800', 'siap' => 'bg-emerald-100 text-emerald-800',
        'selesai' => 'bg-stone-100 text-stone-700', 'dibatalkan' => 'bg-red-100 text-red-800'][$status] ?? 'bg-stone-100 text-stone-700';
@endphp
<span {{ $attributes->merge(['class' => "inline-block rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {$tone}"]) }} data-order-status="{{ $status }}">{{ \App\Models\Order::STATUSES[$status] ?? $status }}</span>
