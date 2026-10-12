{{-- Satu baris transaksi yang bisa diklik menuju halaman detail.
     variant="table" → <tr> untuk tabel desktop; variant="card" → <li> untuk ponsel. --}}
@props(['tx', 'variant' => 'table'])
@php
    $url = route('transaksi.show', $tx['id']);
    $time = substr($tx['created_at'], 11, 5);
    $total = 'Rp'.number_format($tx['total'], 0, ',', '.');
@endphp

@if ($variant === 'table')
    <tr {{ $attributes->class('group cursor-pointer hover:bg-emerald-50/60 focus-within:bg-emerald-50/60') }}
        x-data x-on:click="if (!$event.target.closest('a')) $refs.link.click()">
        <td class="px-4 py-2.5 text-stone-600 tabular-nums">{{ $time }}</td>
        <td class="px-4 py-2.5">
            <a href="{{ $url }}" wire:navigate x-ref="link"
               class="rounded font-mono text-xs text-emerald-800 outline-none group-hover:underline focus-visible:ring-2 focus-visible:ring-emerald-600"
               aria-label="Lihat detail transaksi {{ $tx['invoice_number'] }}">{{ $tx['invoice_number'] }}</a>
        </td>
        <td class="px-4 py-2.5 text-stone-600">{{ $tx['cashier'] }}</td>
        <td class="px-4 py-2.5 text-stone-600">{{ $tx['member'] ?? '—' }}</td>
        <td class="px-4 py-2.5 text-right tabular-nums">{{ $tx['item_count'] }}</td>
        <td class="px-4 py-2.5"><x-transaksi.method-badge :method="$tx['payment_method']" /></td>
        <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ $total }}</td>
        <td class="w-8 pr-3 text-stone-300 group-hover:text-emerald-700" aria-hidden="true">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
        </td>
    </tr>
@else
    <li {{ $attributes }}>
        <a href="{{ $url }}" wire:navigate
           class="flex items-center justify-between gap-3 px-4 py-3 outline-none active:bg-stone-50 focus-visible:bg-emerald-50"
           aria-label="Lihat detail transaksi {{ $tx['invoice_number'] }}">
            <span class="min-w-0">
                <span class="block font-mono text-xs">{{ $tx['invoice_number'] }}</span>
                <span class="block truncate text-xs text-stone-500">{{ $time }} · {{ $tx['item_count'] }} barang · {{ $tx['cashier'] }}</span>
            </span>
            <span class="flex shrink-0 items-center gap-2">
                <span class="text-right">
                    <span class="block font-semibold tabular-nums">{{ $total }}</span>
                    <x-transaksi.method-badge :method="$tx['payment_method']" />
                </span>
                <svg class="size-4 text-stone-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
            </span>
        </a>
    </li>
@endif
