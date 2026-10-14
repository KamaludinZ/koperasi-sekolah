@php $rp = fn ($n) => \App\Support\Rupiah::format($n); $counts = $this->counts; @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Transaksi</h1>
            <p class="text-xs text-stone-500">Pesanan dari portal anggota. Siapkan barang, catat pembayaran tunai/QRIS, lalu selesaikan.</p>
        </div>
        <x-transaksi.nav active="pesanan" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-200/60 p-1" role="tablist" aria-label="Status pesanan">
            <button type="button" role="tab" wire:click="$set('status', '')" aria-selected="{{ $status === '' ? 'true' : 'false' }}"
                    @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $status === '', 'text-stone-600' => $status !== ''])>Semua</button>
            @foreach (\App\Models\Order::STATUSES as $k => $label)
                <button type="button" role="tab" wire:click="$set('status', '{{ $k }}')" aria-selected="{{ $status === $k ? 'true' : 'false' }}"
                        @class(['flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $status === $k, 'text-stone-600' => $status !== $k])>
                    {{ $label }} <span @class(['rounded-full px-1.5 text-[11px] tabular-nums', 'bg-amber-100 text-amber-900' => $k === 'menunggu' && $counts[$k], 'bg-stone-100 text-stone-500' => ! ($k === 'menunggu' && $counts[$k])])>{{ $counts[$k] }}</span>
                </button>
            @endforeach
        </div>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nomor pesanan, nama, atau kode anggota…" aria-label="Cari pesanan"
               class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar pesanan anggota">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[46rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr><th class="px-4 py-2 font-medium">Pesanan</th><th class="px-3 py-2 font-medium">Anggota</th><th class="px-3 py-2 font-medium">Pengambilan</th>
                            <th class="px-3 py-2 font-medium">Pembayaran</th><th class="px-3 py-2 font-medium">Status</th><th class="px-4 py-2 text-right font-medium">Total</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->orders as $o)
                            <tr wire:key="mo-{{ $o->id }}" class="hover:bg-stone-50">
                                <td class="px-4 py-2.5"><button type="button" wire:click="show({{ $o->id }})" class="font-mono text-xs font-semibold text-emerald-800 hover:underline">{{ $o->code }}</button>
                                    <span class="block text-xs text-stone-500">{{ $o->created_at->translatedFormat('d M H:i') }} · {{ $o->item_count }} barang</span></td>
                                <td class="px-3 py-2.5">{{ $o->member->name }}<span class="block font-mono text-xs text-stone-500">{{ $o->member->member_code }}</span></td>
                                <td class="px-3 py-2.5">{{ $o->fulfillment === 'antar' ? 'Antar · '.$o->delivery_to : 'Ambil sendiri' }}</td>
                                <td class="px-3 py-2.5">{{ \App\Models\Order::PAYMENTS[$o->payment_method] }}
                                    <span @class(['block text-xs font-medium', 'text-emerald-800' => $o->payment_status === 'lunas', 'text-amber-800' => $o->payment_status === 'belum', 'text-stone-500' => $o->payment_status === 'dikembalikan'])>{{ \App\Models\Order::PAYMENT_STATUSES[$o->payment_status] }}</span></td>
                                <td class="px-3 py-2.5"><x-portal.order-status :status="$o->status" /></td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ $rp((int) $o->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-stone-400">Belum ada pesanan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @if ($this->orders->hasPages())
            <div class="flex items-center justify-between text-sm">
                <button type="button" wire:click="previousPage" @disabled($this->orders->onFirstPage()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Sebelumnya</button>
                <span class="text-stone-500">Halaman {{ $this->orders->currentPage() }} dari {{ $this->orders->lastPage() }}</span>
                <button type="button" wire:click="nextPage" @disabled(! $this->orders->hasMorePages()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Berikutnya</button>
            </div>
        @endif
    </div>

    @if ($d = $this->detail)
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="mo-title" x-data x-on:keydown.escape.window="$wire.close()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="close"></div>
            <div class="relative flex h-full w-full max-w-lg flex-col bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div>
                        <p class="text-xs text-stone-500">{{ $d['member']['code'] }} · {{ $d['member']['name'] }}{{ $d['member']['class'] ? ' · '.$d['member']['class'] : '' }}</p>
                        <h2 id="mo-title" class="flex items-center gap-2 font-semibold"><span class="font-mono">{{ $d['code'] }}</span> <x-portal.order-status :status="$d['status']" /></h2>
                    </div>
                    <button type="button" wire:click="close" class="text-stone-400 hover:text-stone-600" aria-label="Tutup"><svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
                </div>
                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4 text-sm">
                    <dl class="grid grid-cols-[7rem_1fr] gap-y-1">
                        <dt class="text-stone-500">Pengambilan</dt><dd>{{ $d['fulfillment'] === 'antar' ? 'Diantar ke '.$d['delivery_to'] : 'Ambil sendiri di koperasi' }}</dd>
                        <dt class="text-stone-500">Pembayaran</dt><dd>{{ $d['payment_label'] }}{{ $d['saving_type'] ? ' ('.$d['saving_type'].')' : '' }} · {{ $d['payment_status_label'] }}</dd>
                        @if ($d['note'])<dt class="text-stone-500">Catatan</dt><dd>{{ $d['note'] }}</dd>@endif
                    </dl>
                    <ul class="divide-y divide-stone-100 rounded-lg border border-stone-200">
                        @foreach ($d['items'] as $i)
                            <li class="flex justify-between gap-3 px-3 py-2"><span>{{ $i['name'] }} <span class="text-stone-500">× {{ $i['quantity'] }}</span></span><span class="tabular-nums">{{ $rp($i['subtotal']) }}</span></li>
                        @endforeach
                        <li class="flex justify-between px-3 py-2 font-semibold"><span>Total</span><span class="tabular-nums">{{ $rp($d['total']) }}</span></li>
                    </ul>
                    <section>
                        <h3 class="mb-1 font-semibold">Riwayat status</h3>
                        <ol class="space-y-1 border-l border-stone-200 pl-3 text-xs">
                            @foreach ($d['status_logs'] as $l)
                                <li><span class="font-medium">{{ \App\Models\Order::STATUSES[$l['to']] ?? $l['to'] }}</span> · {{ \Illuminate\Support\Carbon::parse($l['at'])->translatedFormat('d M H:i') }} · {{ $l['actor'] }}@if ($l['note']) — {{ $l['note'] }}@endif</li>
                            @endforeach
                        </ol>
                    </section>
                </div>
                @if (! in_array($d['status'], ['selesai', 'dibatalkan'], true))
                    <div class="space-y-2 border-t border-stone-200 px-5 py-3" data-order-actions>
                        @error('action') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p> @enderror
                        @if ($d['payment_status'] === 'belum' && in_array($d['payment_method'], ['tunai', 'qris'], true))
                            <div class="flex gap-2">
                                <button type="button" wire:click="markPaid('tunai')" class="flex-1 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-100">Terima tunai</button>
                                <button type="button" wire:click="markPaid('qris')" class="flex-1 rounded-lg border border-sky-300 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-900 hover:bg-sky-100">Terima QRIS</button>
                            </div>
                        @endif
                        @foreach ($d['next'] as $to)
                            <input type="text" wire:model="note" maxlength="200" placeholder="Catatan untuk anggota (opsional)" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                            <button type="button" wire:click="advance('{{ $to }}')" class="w-full rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">
                                Tandai {{ $to === 'siap' ? ($d['fulfillment'] === 'antar' ? 'siap diantar' : 'siap diambil') : mb_strtolower(\App\Models\Order::STATUSES[$to]) }}</button>
                        @endforeach
                        @if ($cancelling)
                            <input type="text" wire:model="reason" maxlength="200" placeholder="Alasan pembatalan" class="w-full rounded-lg border border-red-200 px-3 py-2 text-sm">
                            <div class="flex justify-end gap-2">
                                <button type="button" wire:click="$set('cancelling', false)" class="rounded-lg px-3 py-1.5 text-sm text-stone-600 hover:bg-stone-100">Tidak jadi</button>
                                <button type="button" wire:click="cancel" class="rounded-lg bg-red-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-800">Batalkan pesanan</button>
                            </div>
                        @else
                            <button type="button" wire:click="$set('cancelling', true)" class="w-full text-center text-sm font-semibold text-red-700 hover:underline">Batalkan pesanan…</button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
