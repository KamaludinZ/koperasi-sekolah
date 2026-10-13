@use('App\Livewire\Pengadaan\Index')
@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $s = $this->summary;
    $today = now()->toDateString();
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Pengadaan Barang</h1>
                <p class="text-xs text-stone-500">Pesan barang ke supplier, catat penerimaannya, dan pantau hutang pembelian.</p>
            </div>
            <div class="flex items-center gap-2">
                @can('kelola-pengadaan')
                @foreach ([['pengadaan.penerimaan', 'Catat penerimaan', false], ['pengadaan.pesanan.buat', 'Buat pesanan', true]] as [$route, $label, $primary])
                    @if ($url = Index::link($route))
                        <a href="{{ $url }}" wire:navigate @class(['rounded-xl px-4 py-2.5 text-sm font-semibold', 'bg-emerald-700 text-white hover:bg-emerald-800' => $primary, 'border border-stone-300 bg-white text-stone-700 hover:bg-stone-50' => ! $primary])>{{ $label }}</a>
                    @else
                        <span title="Segera hadir" @class(['cursor-not-allowed rounded-xl px-4 py-2.5 text-sm font-semibold opacity-60', 'bg-emerald-700 text-white' => $primary, 'border border-stone-300 bg-white text-stone-700' => ! $primary])>{{ $label }}</span>
                    @endif
                @endforeach
                @endcan
            </div>
        </div>
        <x-pengadaan.nav active="pengadaan" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        {{-- Ringkasan --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Pesanan berjalan</p>
                <p class="text-2xl font-bold tabular-nums">{{ $s['open_orders'] }}</p>
                <p class="text-xs text-stone-500 tabular-nums">{{ $rp($s['open_value']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Menunggu diterima</p>
                <p class="text-2xl font-bold tabular-nums">{{ number_format($s['awaiting_qty'], 0, ',', '.') }}</p>
                <p class="text-xs text-stone-500">unit barang</p>
            </div>
            <div @class(['rounded-xl border px-4 py-3', 'border-red-200 bg-red-50' => $s['overdue'] > 0, 'border-stone-200 bg-white' => $s['overdue'] === 0])>
                <p class="text-xs text-stone-500">Hutang belum dibayar</p>
                <p class="text-2xl font-bold tabular-nums">{{ $rp($s['payable']) }}</p>
                <p @class(['text-xs', 'font-semibold text-red-700' => $s['overdue'] > 0, 'text-stone-500' => $s['overdue'] === 0])>
                    {{ $s['overdue'] ? $s['overdue'].' lewat jatuh tempo' : ($s['due_soon'] ? $s['due_soon'].' jatuh tempo ≤ '.Index::DUE_SOON_DAYS.' hari' : 'tidak ada yang mendesak') }}
                </p>
            </div>
            <div @class(['rounded-xl border px-4 py-3', 'border-amber-200 bg-amber-50' => $s['restock'] > 0, 'border-stone-200 bg-white' => $s['restock'] === 0])>
                <p class="text-xs text-stone-500">Perlu dipesan</p>
                <p class="text-2xl font-bold tabular-nums">{{ $s['restock'] }}</p>
                <p class="text-xs text-stone-500">barang stok menipis · {{ $s['suppliers'] }} supplier aktif</p>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
            {{-- Pesanan berjalan --}}
            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="po-title">
                <div class="flex items-center justify-between px-4 pt-4">
                    <h2 id="po-title" class="font-semibold">Pesanan berjalan</h2>
                    @if ($url = Index::link('pengadaan.pesanan'))<a href="{{ $url }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:underline">Semua pesanan →</a>@endif
                </div>
                <ul class="mt-2 divide-y divide-stone-100">
                    @forelse ($this->openOrders as $o)
                        @php [$label, $tone] = Index::ORDER_STATUSES[$o['status']]; $pct = $o['qty'] ? round($o['received_qty'] / $o['qty'] * 100) : 0; $late = $o['status'] !== 'draf' && $o['expected_at'] < $today; @endphp
                        <li class="px-4 py-3" wire:key="po-{{ $o['id'] }}">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-mono text-xs font-semibold">{{ $o['number'] }}</span>
                                <span class="rounded-md px-2 py-0.5 text-[11px] font-semibold {{ $tone }}">{{ $label }}</span>
                                <span class="ml-auto text-sm font-semibold tabular-nums">{{ $rp($o['total']) }}</span>
                            </div>
                            <p class="text-sm">{{ $o['supplier'] }} <span class="text-xs text-stone-500">· {{ $o['items'] }} jenis barang</span></p>
                            <div class="mt-1.5 flex items-center gap-2">
                                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-stone-100" aria-hidden="true"><span class="block h-full rounded-full bg-emerald-600" style="width: {{ $pct }}%"></span></span>
                                <span class="text-xs text-stone-600 tabular-nums">{{ $o['received_qty'] }}/{{ $o['qty'] }} diterima</span>
                            </div>
                            <p @class(['mt-1 text-xs', 'font-semibold text-red-700' => $late, 'text-stone-500' => ! $late])>
                                {{ $o['status'] === 'draf' ? 'Belum dikirim ke supplier' : ($late ? 'Terlambat — dijadwalkan ' : 'Diperkirakan tiba ').\Illuminate\Support\Carbon::parse($o['expected_at'])->translatedFormat('d M') }}
                            </p>
                        </li>
                    @empty
                        <li class="px-4 py-8 text-center text-sm text-stone-400">Tidak ada pesanan yang berjalan.</li>
                    @endforelse
                </ul>
            </section>

            <div class="space-y-5">
                {{-- Hutang --}}
                <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="ap-title">
                    <div class="flex items-center justify-between px-4 pt-4">
                        <h2 id="ap-title" class="font-semibold">Hutang jatuh tempo</h2>
                        @if ($url = Index::link('pengadaan.hutang'))<a href="{{ $url }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:underline">Semua hutang →</a>@endif
                    </div>
                    <ul class="mt-2 divide-y divide-stone-100">
                        @forelse ($this->payables as $p)
                            @php $days = (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($p['due_at']), false); @endphp
                            <li class="flex items-center gap-3 px-4 py-2.5" wire:key="ap-{{ $p['id'] }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium">{{ $p['supplier'] }}</span>
                                    <span class="block text-xs text-stone-500"><span class="font-mono">{{ $p['po_number'] }}</span>@if ($p['paid']) · dibayar {{ $rp($p['paid']) }}@endif</span>
                                </span>
                                <span class="text-right">
                                    <span class="block text-sm font-semibold tabular-nums">{{ $rp($p['amount'] - $p['paid']) }}</span>
                                    <span @class(['block text-xs', 'font-semibold text-red-700' => $days < 0, 'font-semibold text-amber-800' => $days >= 0 && $days <= Index::DUE_SOON_DAYS, 'text-stone-500' => $days > Index::DUE_SOON_DAYS])>
                                        {{ $days < 0 ? 'lewat '.abs($days).' hari' : ($days === 0 ? 'jatuh tempo hari ini' : 'jatuh tempo '.$days.' hari lagi') }}
                                    </span>
                                </span>
                            </li>
                        @empty
                            <li class="px-4 py-8 text-center text-sm text-stone-400">Semua hutang sudah lunas.</li>
                        @endforelse
                    </ul>
                </section>

                {{-- Perlu dipesan --}}
                <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="rs-title">
                    <div class="px-4 pt-4">
                        <h2 id="rs-title" class="font-semibold">Perlu dipesan</h2>
                        <p class="text-xs text-stone-500">Barang aktif yang stoknya di bawah batas minimum. Saran jumlah = 2× batas minimum − stok.</p>
                    </div>
                    <ul class="mt-2 divide-y divide-stone-100">
                        @forelse ($this->restock as $r)
                            <li class="flex items-center gap-3 px-4 py-2.5" wire:key="rs-{{ $r['id'] }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium"><span class="font-mono text-xs">{{ $r['code'] }}</span> {{ $r['name'] }}</span>
                                    <span class="block text-xs text-stone-500">stok {{ $r['stock'] }} / min. {{ $r['min_stock'] }} {{ $r['unit'] }} · {{ $r['supplier'] ?? 'belum ada supplier untuk kategorinya' }}</span>
                                </span>
                                <span class="shrink-0 rounded-lg bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900 tabular-nums">pesan {{ $r['suggested'] }} {{ $r['unit'] }}</span>
                            </li>
                        @empty
                            <li class="px-4 py-8 text-center text-sm text-stone-400">Semua stok aman.</li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </div>

        {{-- Supplier --}}
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="sup-title">
            <div class="flex items-center justify-between px-4 pt-4">
                <h2 id="sup-title" class="font-semibold">Supplier</h2>
                @if ($url = Index::link('pengadaan.supplier'))<a href="{{ $url }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:underline">Kelola supplier →</a>@endif
            </div>
            <div class="mt-3 grid gap-3 px-4 pb-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($this->suppliers as $sup)
                    <div wire:key="sup-{{ $sup['id'] }}" @class(['rounded-lg border p-3', 'border-stone-200' => $sup['is_active'], 'border-dashed border-stone-300 opacity-70' => ! $sup['is_active']])>
                        <p class="flex items-center justify-between gap-2"><span class="truncate font-medium">{{ $sup['name'] }}</span>
                            @unless ($sup['is_active'])<span class="shrink-0 rounded bg-stone-200 px-1.5 text-[11px] text-stone-600">nonaktif</span>@endunless</p>
                        <p class="text-xs text-stone-500">{{ implode(', ', $sup['categories']) }} · {{ $sup['terms_days'] ? 'tempo '.$sup['terms_days'].' hari' : 'tunai' }}</p>
                        <p class="mt-1 text-xs text-stone-600">90 hari: <span class="font-semibold tabular-nums">{{ $rp($sup['purchases_90d']) }}</span>@if ($sup['open_payable']) · hutang <span class="font-semibold text-amber-800 tabular-nums">{{ $rp($sup['open_payable']) }}</span>@endif</p>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</div>
