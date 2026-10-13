@use('App\Livewire\Transaksi\Returns')
@php
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    $dt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y, H:i');
    $s = $this->stats;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Riwayat Transaksi</h1>
                <p class="text-xs text-stone-500">Retur barang dan pembatalan transaksi beserta status persetujuannya.</p>
            </div>
            <span class="rounded bg-stone-100 px-2 py-1 text-xs font-medium text-stone-500" title="Menunggu backend retur">Data contoh</span>
        </div>
        <x-transaksi.nav active="transaksi.retur" />
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 2xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 space-y-4">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div @class(['rounded-xl border px-4 py-3', 'border-amber-200 bg-amber-50' => $s['pending'], 'border-stone-200 bg-white' => ! $s['pending']])>
                    <p class="text-xs text-stone-600">Menunggu persetujuan</p>
                    <p class="text-2xl font-bold tabular-nums">{{ $s['pending'] }}</p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Retur bulan ini</p>
                    <p class="text-xl font-bold tabular-nums">{{ $rp($s['return_total']) }}</p>
                    <p class="text-xs text-stone-500">{{ $s['return_count'] }} pengajuan disetujui</p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Pembatalan bulan ini</p>
                    <p class="text-xl font-bold tabular-nums">{{ $rp($s['void_total']) }}</p>
                    <p class="text-xs text-stone-500">{{ $s['void_count'] }} transaksi</p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Kembali ke stok</p>
                    <p class="text-2xl font-bold tabular-nums">{{ $s['restocked'] }} <span class="text-sm font-normal text-stone-500">unit</span></p>
                </div>
            </div>

            <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-200/60 p-1" role="tablist" aria-label="Status pengajuan">
                @foreach (Returns::STATUSES as $key => $label)
                    <button type="button" role="tab" wire:click="setStatus('{{ $key }}')" aria-selected="{{ $status === $key ? 'true' : 'false' }}"
                            @class(['flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $status === $key, 'text-stone-600 hover:text-stone-900' => $status !== $key])>
                        {{ $label }} <span @class(['rounded-full px-1.5 text-[11px] tabular-nums', 'bg-amber-100 text-amber-900' => $key === 'menunggu' && $this->counts[$key], 'bg-stone-100 text-stone-500' => ! ($key === 'menunggu' && $this->counts[$key])])>{{ $this->counts[$key] }}</span>
                    </button>
                @endforeach
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <label class="relative flex-1">
                    <span class="sr-only">Cari pengajuan</span>
                    <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nomor retur, nomor transaksi, atau barang…"
                           class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                </label>
                <select wire:model.live="kind" aria-label="Jenis pengajuan" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                    @foreach (Returns::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </div>

            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar retur & pembatalan">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[48rem] text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500">
                            <tr>
                                <th class="px-4 py-2 font-medium">Pengajuan</th>
                                <th class="px-3 py-2 font-medium">Transaksi</th>
                                <th class="px-3 py-2 font-medium">Alasan</th>
                                <th class="px-3 py-2 font-medium">Status</th>
                                <th class="px-4 py-2 text-right font-medium">Nilai</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($this->rows as $r)
                                <tr wire:key="r-{{ $r['id'] }}" class="hover:bg-stone-50">
                                    <td class="px-4 py-2.5">
                                        <button type="button" wire:click="show({{ $r['id'] }})" class="font-mono text-xs font-semibold text-emerald-800 hover:underline">{{ $r['number'] }}</button>
                                        <span class="block text-xs text-stone-500">{{ $r['kind'] === 'batal' ? 'Pembatalan' : 'Retur '.$r['qty'].' barang' }} · {{ $dt($r['requested_at']) }}</span>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <a href="{{ route('transaksi.show', $r['transaction']['id']) }}" wire:navigate class="font-mono text-xs hover:text-emerald-800 hover:underline">{{ $r['transaction']['invoice'] }}</a>
                                        <span class="block text-xs text-stone-500">{{ $r['requested_by'] }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-stone-700">{{ $r['reason'] }}</td>
                                    <td class="px-3 py-2.5"><span class="rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ Returns::STATUS_TONES[$r['status']] }}">{{ Returns::STATUSES[$r['status']] }}</span></td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ $rp($r['amount']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-10 text-center text-stone-400">{{ $this->all ? 'Tidak ada pengajuan yang cocok.' : 'Belum ada retur atau pembatalan.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        {{-- Mulai pengajuan --}}
        <aside class="h-fit space-y-3 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="rt-new">
            <h2 id="rt-new" class="font-semibold">Ajukan retur / pembatalan</h2>
            <p class="text-xs text-stone-500">Cari nomor transaksi di struk pembeli. Retur & pembatalan paling lambat {{ Returns::LIMIT_DAYS }} hari setelah pembelian dan perlu persetujuan Admin/Pengurus.</p>
            <label class="block">
                <span class="sr-only">Nomor transaksi</span>
                <input type="search" wire:model.live.debounce.300ms="lookup" placeholder="mis. INV-20261013-0007" autocomplete="off"
                       class="w-full rounded-lg border border-stone-300 px-3 py-2 font-mono text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
            @if ($this->lookupResults)
                <ul class="divide-y divide-stone-100 rounded-lg border border-stone-200">
                    @foreach ($this->lookupResults as $t)
                        <li wire:key="lk-{{ $t['id'] }}" class="space-y-2 px-3 py-2.5 text-sm">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="font-mono text-xs font-semibold">{{ $t['invoice'] }}</span>
                                <span class="font-semibold tabular-nums">{{ $rp($t['total']) }}</span>
                            </div>
                            <p class="text-xs text-stone-500">{{ $dt($t['created_at']) }} · {{ $t['items'] }} barang · <x-transaksi.method-badge :method="$t['method']" /></p>
                            @if ($t['expired'])
                                <p class="text-xs font-medium text-red-700">Lewat batas {{ Returns::LIMIT_DAYS }} hari — tidak bisa diretur.</p>
                            @else
                                <div class="grid grid-cols-2 gap-2">
                                    @foreach (['retur' => 'Retur barang', 'batal' => 'Batalkan'] as $k => $label)
                                        @if ($url = Returns::formLink($k, $t['id']))
                                            <a href="{{ $url }}" wire:navigate @class(['rounded-lg px-2 py-1.5 text-center text-xs font-semibold', 'bg-emerald-700 text-white hover:bg-emerald-800' => $k === 'retur', 'border border-red-300 text-red-700 hover:bg-red-50' => $k === 'batal'])>{{ $label }}</a>
                                        @else
                                            <span title="Segera hadir" class="cursor-not-allowed rounded-lg border border-stone-200 px-2 py-1.5 text-center text-xs font-semibold text-stone-400">{{ $label }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @elseif (mb_strlen(trim($lookup)) >= 3)
                <p class="text-xs text-stone-500">Transaksi tidak ditemukan.</p>
            @endif
        </aside>
    </div>

    @if ($sel = $this->selected)
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="rt-detail-title"
             x-data x-on:keydown.escape.window="$wire.closeDetail()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="closeDetail"></div>
            <div class="relative flex h-full w-full max-w-lg flex-col bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div>
                        <p class="text-xs text-stone-500">{{ $sel['kind'] === 'batal' ? 'Pembatalan transaksi' : 'Retur barang' }}</p>
                        <h2 id="rt-detail-title" class="flex items-center gap-2 font-semibold"><span class="font-mono">{{ $sel['number'] }}</span>
                            <span class="rounded-md px-2 py-0.5 text-[11px] font-semibold {{ Returns::STATUS_TONES[$sel['status']] }}">{{ Returns::STATUSES[$sel['status']] }}</span></h2>
                    </div>
                    <button type="button" wire:click="closeDetail" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 text-sm">
                    <dl class="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-2">
                        <dt class="text-stone-500">Transaksi</dt>
                        <dd><a href="{{ route('transaksi.show', $sel['transaction']['id']) }}" wire:navigate class="font-mono text-emerald-800 hover:underline">{{ $sel['transaction']['invoice'] }}</a>
                            <span class="block text-xs text-stone-500">{{ $dt($sel['transaction']['created_at']) }} · {{ $rp($sel['transaction']['total']) }} · kasir {{ $sel['transaction']['cashier'] }}</span></dd>
                        <dt class="text-stone-500">Alasan</dt><dd>{{ $sel['reason'] }}@if ($sel['note'])<span class="block text-xs text-stone-500">{{ $sel['note'] }}</span>@endif</dd>
                        <dt class="text-stone-500">Pengembalian dana</dt><dd>{{ $rp($sel['amount']) }} · {{ Returns::REFUND_METHODS[$sel['refund_method']] }}</dd>
                        <dt class="text-stone-500">Stok</dt><dd>{{ $sel['restock'] ? 'Barang dikembalikan ke stok' : 'Tidak kembali ke stok (rusak/kedaluwarsa)' }}</dd>
                        <dt class="text-stone-500">Diajukan</dt><dd>{{ $sel['requested_by'] }} · {{ $dt($sel['requested_at']) }}</dd>
                        @if ($sel['decided_by'])
                            <dt class="text-stone-500">{{ $sel['status'] === 'ditolak' ? 'Ditolak' : 'Disetujui' }}</dt>
                            <dd>{{ $sel['decided_by'] }} · {{ $dt($sel['decided_at']) }}@if ($sel['decision_note'])<span class="block text-xs text-red-700">{{ $sel['decision_note'] }}</span>@endif</dd>
                        @endif
                    </dl>
                    <section>
                        <h3 class="mb-2 font-semibold">{{ $sel['kind'] === 'batal' ? 'Barang dalam transaksi' : 'Barang diretur' }}</h3>
                        <table class="w-full text-xs">
                            <thead class="text-left text-stone-500"><tr><th class="py-1 font-medium">Barang</th><th class="py-1 text-right font-medium">Jumlah</th><th class="py-1 text-right font-medium">Nilai</th></tr></thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($sel['items'] as $it)
                                    <tr><td class="py-1.5">{{ $it['name'] }}</td><td class="py-1.5 text-right tabular-nums">{{ $it['qty'] }}</td><td class="py-1.5 text-right tabular-nums">{{ $rp($it['amount']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                    @if ($sel['status'] === 'menunggu')
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Menunggu persetujuan Admin/Pengurus. Stok dan uang belum berubah sampai disetujui.</p>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
