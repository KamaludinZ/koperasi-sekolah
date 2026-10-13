@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $date = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y');
    $field = fn ($f) => 'rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-4 '.($errors->has($f) ? 'border-red-400 focus:ring-red-500/15' : 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15');
    $o = $this->order;
    $now = array_sum(array_map(fn ($v) => max(0, (int) $v), $qty));
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Pengadaan Barang</h1>
                <p class="text-xs text-stone-500">Catat barang yang datang dari supplier. Stok bertambah sesuai jumlah yang diterima.</p>
            </div>
        </div>
        <x-pengadaan.nav active="pengadaan.penerimaan" />
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 2xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 space-y-5">
            {{-- Pilih pesanan --}}
            <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="rc-po">
                <h2 id="rc-po" class="font-semibold">Pesanan yang datang</h2>
                @if ($this->waiting)
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        @foreach ($this->waiting as $w)
                            @php $late = $w['expected_at'] < now()->toDateString(); @endphp
                            <label wire:key="w-{{ $w['id'] }}" class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-stone-300 px-3 py-2.5 text-sm has-checked:border-emerald-600 has-checked:bg-emerald-50">
                                <input type="radio" wire:model.live="orderId" value="{{ $w['id'] }}" class="mt-0.5 accent-emerald-700" @checked((string) $w['id'] === $orderId)>
                                <span class="min-w-0">
                                    <span class="block"><span class="font-mono text-xs font-semibold">{{ $w['number'] }}</span> · {{ $w['supplier'] }}</span>
                                    <span @class(['block text-xs', 'font-semibold text-red-700' => $late, 'text-stone-500' => ! $late])>{{ $w['received_qty'] }}/{{ $w['qty'] }} diterima · {{ $late ? 'terlambat sejak' : 'tiba' }} {{ $date($w['expected_at']) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @else
                    <p class="mt-2 text-sm text-stone-500">Tidak ada pesanan yang sedang ditunggu.</p>
                @endif
                @error('orderId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </section>

            @if ($o)
                <section class="rounded-2xl border border-stone-200 bg-white" aria-labelledby="rc-items">
                    <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
                        <h2 id="rc-items" class="font-semibold">Barang dari {{ $o['number'] }}</h2>
                        <button type="button" wire:click="receiveAll" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Isi semua sisa</button>
                    </div>
                    @error('qty') <p class="px-5 pt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-sm">
                            <thead class="bg-stone-50 text-left text-xs text-stone-500">
                                <tr><th class="px-5 py-2 font-medium">Barang</th><th class="px-3 py-2 text-right font-medium">Dipesan</th><th class="px-3 py-2 text-right font-medium">Sudah diterima</th><th class="px-3 py-2 font-medium">Datang sekarang</th><th class="px-5 py-2 font-medium">Stok</th></tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($this->lines as $l)
                                    @php $left = $l['qty'] - $l['received_qty']; $in = max(0, (int) ($qty[$l['id']] ?? 0)); @endphp
                                    <tr wire:key="rl-{{ $l['id'] }}" @class(['opacity-60' => $left === 0])>
                                        <td class="px-5 py-2"><span class="font-mono text-xs">{{ $l['code'] }}</span> {{ $l['name'] }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $l['qty'] }} {{ $l['unit'] }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $l['received_qty'] }}</td>
                                        <td class="px-3 py-2">
                                            @if ($left > 0)
                                                <input type="number" min="0" max="{{ $left }}" wire:model.live.debounce.400ms="qty.{{ $l['id'] }}" value="{{ $qty[$l['id']] ?? '' }}"
                                                       aria-label="Jumlah datang {{ $l['name'] }}" class="{{ $field('qty.'.$l['id']) }} w-24 tabular-nums">
                                                <span class="text-xs text-stone-500">dari sisa {{ $left }}</span>
                                                @error('qty.'.$l['id']) <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                                            @else
                                                <span class="text-xs font-medium text-emerald-800">Lengkap</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-2 text-xs whitespace-nowrap tabular-nums">{{ $l['stock'] }}@if ($in) → <span class="font-semibold text-emerald-800">{{ $l['stock'] + $in }}</span>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="grid gap-4 border-t border-stone-100 p-5 sm:grid-cols-2">
                        <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Tanggal diterima</span>
                            <input type="date" wire:model="receivedAt" value="{{ $receivedAt }}" max="{{ now()->toDateString() }}" class="{{ $field('receivedAt') }} w-full">
                            @error('receivedAt') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">No. surat jalan <span class="font-normal text-stone-500">(opsional)</span></span>
                            <input type="text" wire:model="deliveryNote" value="{{ $deliveryNote }}" maxlength="50" placeholder="mis. SJ-0912" class="{{ $field('deliveryNote') }} w-full">
                        </label>
                        <label class="block text-sm sm:col-span-2"><span class="mb-1 block font-medium text-stone-700">Catatan <span class="font-normal text-stone-500">(opsional)</span></span>
                            <input type="text" wire:model="note" value="{{ $note }}" maxlength="200" placeholder="mis. 2 bungkus penyok, diganti minggu depan" class="{{ $field('note') }} w-full">
                        </label>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 px-5 py-4">
                        <p class="text-sm text-stone-600"><span class="font-semibold tabular-nums">{{ $now }}</span> unit akan ditambahkan ke stok.</p>
                        <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Simpan penerimaan</button>
                    </div>
                </section>
            @endif
        </div>

        {{-- Riwayat --}}
        <aside class="h-fit overflow-hidden rounded-2xl border border-stone-200 bg-white" aria-labelledby="rc-hist">
            <h2 id="rc-hist" class="px-4 pt-4 font-semibold">Penerimaan terbaru</h2>
            <ul class="mt-2 divide-y divide-stone-100">
                @forelse ($this->receipts as $r)
                    <li class="px-4 py-2.5 text-sm" wire:key="rc-{{ $r['id'] }}">
                        <p class="flex justify-between gap-2"><span class="font-mono text-xs font-semibold">{{ $r['order_number'] }}</span><span class="text-xs text-stone-500">{{ $date($r['received_at']) }}</span></p>
                        <p>{{ $r['supplier'] }} · <span class="tabular-nums">{{ $r['qty'] }} unit</span> <span class="text-xs text-stone-500">({{ $rp($r['value']) }})</span></p>
                        <p class="text-xs text-stone-500">{{ $r['number'] }} · {{ $r['user'] }}@if ($r['delivery_note']) · {{ $r['delivery_note'] }}@endif</p>
                        @if ($r['note'])<p class="text-xs text-amber-800">{{ $r['note'] }}</p>@endif
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-stone-400">Belum ada penerimaan.</li>
                @endforelse
            </ul>
        </aside>
    </div>
</div>
