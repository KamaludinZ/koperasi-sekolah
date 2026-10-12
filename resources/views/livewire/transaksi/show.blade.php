@php
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    $at = \Illuminate\Support\Carbon::parse($tx['created_at']);
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto"
     x-data
     x-on:keydown.window="
        if (['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName)) return;
        if ($event.key === 'Escape' || $event.key === 'Backspace') { $event.preventDefault(); $refs.back.click() }
        if ($event.key === 'ArrowLeft' && $refs.newer) $refs.newer.click()
        if ($event.key === 'ArrowRight' && $refs.older) $refs.older.click()">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-3 sm:px-6">
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ route('transaksi') }}" wire:navigate x-ref="back" aria-label="Kembali ke riwayat transaksi"
               class="grid size-9 shrink-0 place-items-center rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></svg>
            </a>
            <div class="min-w-0">
                <h1 class="truncate font-mono text-base font-semibold">{{ $tx['invoice_number'] }}</h1>
                <p class="text-xs text-stone-500">{{ $at->translatedFormat('l, d F Y · H:i') }} · {{ $tx['cashier'] }}</p>
            </div>
        </div>

        <nav class="flex items-center gap-1" aria-label="Navigasi transaksi">
            @if ($newerId)
                <a href="{{ route('transaksi.show', $newerId) }}" wire:navigate x-ref="newer"
                   class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-50">‹ Lebih baru</a>
            @else
                <span class="rounded-lg border border-stone-200 px-3 py-1.5 text-sm text-stone-300">‹ Lebih baru</span>
            @endif
            @if ($olderId)
                <a href="{{ route('transaksi.show', $olderId) }}" wire:navigate x-ref="older"
                   class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-50">Lebih lama ›</a>
            @else
                <span class="rounded-lg border border-stone-200 px-3 py-1.5 text-sm text-stone-300">Lebih lama ›</span>
            @endif
        </nav>
    </header>

    <div class="px-4 py-5 sm:px-6">
        <div class="max-w-2xl rounded-xl border border-stone-200 bg-white px-5 py-4">
            <div class="flex items-baseline justify-between">
                <span class="text-sm text-stone-600">Total · {{ $tx['item_count'] }} barang · <x-transaksi.method-badge :method="$tx['payment_method']" /></span>
                <span class="text-2xl font-bold text-emerald-900 tabular-nums">{{ $rp($tx['total']) }}</span>
            </div>
        </div>
        <p class="mt-3 text-xs text-stone-400">Gunakan ← → untuk berpindah transaksi, Esc untuk kembali.</p>
    </div>
</div>
