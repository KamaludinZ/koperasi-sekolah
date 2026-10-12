{{-- Paginasi riwayat transaksi. Butuh: $paginator (LengthAwarePaginator). --}}
@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $pages = collect(range(1, $last))->filter(fn ($n) => $n === 1 || $n === $last || abs($n - $current) <= 1)->values();
    $btn = 'grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-medium tabular-nums';
@endphp
<nav class="flex flex-col-reverse items-center justify-between gap-3 sm:flex-row" aria-label="Paginasi riwayat transaksi">
    <span class="text-xs text-stone-500">
        Menampilkan <strong class="text-stone-700 tabular-nums">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</strong>
        dari <strong class="text-stone-700 tabular-nums">{{ number_format($paginator->total(), 0, ',', '.') }}</strong> transaksi
    </span>
    <div class="flex items-center gap-1">
        <button type="button" wire:click="previousPage" x-on:click="$el.closest('.overflow-y-auto')?.scrollTo({top: 0})" @disabled($paginator->onFirstPage()) aria-label="Halaman sebelumnya"
                class="{{ $btn }} border border-stone-300 bg-white text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-40">‹</button>
        @foreach ($pages as $i => $n)
            @if ($i > 0 && $n - $pages[$i - 1] > 1)
                <span class="px-1 text-stone-400">…</span>
            @endif
            <button type="button" wire:click="gotoPage({{ $n }})" x-on:click="$el.closest('.overflow-y-auto')?.scrollTo({top: 0})" @if ($n === $current) aria-current="page" @endif
                    @class([$btn, 'bg-emerald-800 text-white' => $n === $current,
                            'border border-stone-300 bg-white text-stone-700 hover:bg-stone-50' => $n !== $current])>{{ $n }}</button>
        @endforeach
        <button type="button" wire:click="nextPage" x-on:click="$el.closest('.overflow-y-auto')?.scrollTo({top: 0})" @disabled(! $paginator->hasMorePages()) aria-label="Halaman berikutnya"
                class="{{ $btn }} border border-stone-300 bg-white text-stone-700 hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-40">›</button>
    </div>
</nav>
