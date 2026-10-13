@use('App\Livewire\Pengadaan\Index')
@use('App\Livewire\Pengadaan\Orders')
@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $date = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y');
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Pengadaan Barang</h1>
                <p class="text-xs text-stone-500">Pesanan pembelian ke supplier dan kemajuan penerimaannya.</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">Data contoh</span>
                @if ($url = Index::link('pengadaan.pesanan.buat'))
                    <a href="{{ $url }}" wire:navigate class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Buat pesanan</a>
                @else
                    <span title="Segera hadir" class="cursor-not-allowed rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white opacity-60">Buat pesanan</span>
                @endif
            </div>
        </div>
        <x-pengadaan.nav active="pengadaan.pesanan" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-200/60 p-1" role="tablist" aria-label="Status pesanan">
            @foreach ($this->tabs as $key => [$label, $n])
                <button type="button" role="tab" wire:click="setStatus('{{ $key }}')" aria-selected="{{ $status === $key ? 'true' : 'false' }}"
                        @class(['flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $status === $key, 'text-stone-600 hover:text-stone-900' => $status !== $key])>
                    {{ $label }} <span @class(['rounded-full px-1.5 text-[11px] tabular-nums', 'bg-red-100 text-red-800' => $key === 'terlambat' && $n > 0, 'bg-stone-100 text-stone-500' => ! ($key === 'terlambat' && $n > 0)])>{{ $n }}</span>
                </button>
            @endforeach
        </div>

        <div class="flex flex-col gap-2 sm:flex-row">
            <label class="relative flex-1">
                <span class="sr-only">Cari pesanan</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nomor pesanan atau supplier…"
                       class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
            <select wire:model.live="supplier" aria-label="Supplier" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">Semua supplier</option>
                @foreach ($this->supplierOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar pesanan">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[50rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Pesanan</th>
                            <th class="px-3 py-2 font-medium">Supplier</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Perkiraan tiba</th>
                            <th class="px-3 py-2 font-medium">Diterima</th>
                            <th class="px-4 py-2 text-right font-medium">Nilai</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->orders as $o)
                            @php [$label, $tone] = Index::ORDER_STATUSES[$o['status']]; $late = Orders::isLate($o); $pct = $o['qty'] ? round($o['received_qty'] / $o['qty'] * 100) : 0; @endphp
                            <tr wire:key="po-{{ $o['id'] }}" class="hover:bg-stone-50">
                                <td class="px-4 py-2.5">
                                    <button type="button" wire:click="show({{ $o['id'] }})" class="font-mono text-xs font-semibold text-emerald-800 hover:underline">{{ $o['number'] }}</button>
                                    <span class="block text-xs text-stone-500">{{ $date($o['ordered_at']) }} · {{ $o['created_by'] }}</span>
                                </td>
                                <td class="px-3 py-2.5">{{ $o['supplier'] }}</td>
                                <td class="px-3 py-2.5"><span class="rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $tone }}">{{ $label }}</span></td>
                                <td @class(['px-3 py-2.5 whitespace-nowrap', 'font-semibold text-red-700' => $late, 'text-stone-600' => ! $late])>
                                    {{ in_array($o['status'], ['diterima', 'dibatalkan'], true) ? '—' : $date($o['expected_at']) }}@if ($late) · terlambat @endif
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="flex items-center gap-2">
                                        <span class="h-1.5 w-20 overflow-hidden rounded-full bg-stone-100" aria-hidden="true"><span class="block h-full rounded-full bg-emerald-600" style="width: {{ $pct }}%"></span></span>
                                        <span class="text-xs text-stone-600 tabular-nums">{{ $o['received_qty'] }}/{{ $o['qty'] }}</span>
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ $rp($o['total']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-stone-400">Tidak ada pesanan yang cocok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @if ($sel = $this->selected)
        @php [$label, $tone] = Index::ORDER_STATUSES[$sel['status']]; @endphp
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="po-detail-title"
             x-data x-on:keydown.escape.window="$wire.closeDetail()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="closeDetail"></div>
            <div class="relative flex h-full w-full max-w-lg flex-col bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div>
                        <p class="text-xs text-stone-500">{{ $sel['supplier'] }}</p>
                        <h2 id="po-detail-title" class="flex items-center gap-2 font-semibold"><span class="font-mono">{{ $sel['number'] }}</span>
                            <span class="rounded-md px-2 py-0.5 text-[11px] font-semibold {{ $tone }}">{{ $label }}</span></h2>
                    </div>
                    <button type="button" wire:click="closeDetail" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 text-sm">
                    <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-2">
                        <dt class="text-stone-500">Dipesan</dt><dd>{{ $date($sel['ordered_at']) }} oleh {{ $sel['created_by'] }}</dd>
                        <dt class="text-stone-500">Perkiraan tiba</dt><dd>{{ $date($sel['expected_at']) }}</dd>
                        <dt class="text-stone-500">Nilai pesanan</dt><dd class="font-semibold tabular-nums">{{ $rp($sel['total']) }}</dd>
                    </dl>
                    <section>
                        <h3 class="mb-2 font-semibold">Barang</h3>
                        <table class="w-full text-xs">
                            <thead class="text-left text-stone-500"><tr><th class="py-1 font-medium">Barang</th><th class="py-1 text-right font-medium">Dipesan</th><th class="py-1 text-right font-medium">Diterima</th><th class="py-1 text-right font-medium">Harga beli</th></tr></thead>
                            <tbody class="divide-y divide-stone-100">
                                @forelse ($sel['lines'] as $l)
                                    <tr>
                                        <td class="py-1.5"><span class="font-mono">{{ $l['code'] }}</span> {{ $l['name'] }}</td>
                                        <td class="py-1.5 text-right tabular-nums">{{ $l['qty'] }} {{ $l['unit'] }}</td>
                                        <td @class(['py-1.5 text-right tabular-nums', 'text-emerald-800' => $l['received_qty'] >= $l['qty'], 'text-amber-800' => $l['received_qty'] < $l['qty']])>{{ $l['received_qty'] }}</td>
                                        <td class="py-1.5 text-right tabular-nums">{{ $rp($l['cost']) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="py-4 text-center text-stone-400">Rincian barang belum tersedia.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </section>
                </div>
                @if (in_array($sel['status'], ['dipesan', 'sebagian'], true) && ($url = Index::link('pengadaan.penerimaan', ['pesanan' => $sel['id']])))
                    <div class="border-t border-stone-200 px-5 py-3">
                        <a href="{{ $url }}" wire:navigate class="block w-full rounded-xl bg-emerald-700 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-emerald-800">Catat penerimaan barang</a>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
