@use('App\Livewire\Pengadaan\Payables')
@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $date = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y');
    $field = fn ($f) => 'rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-4 '.($errors->has($f) ? 'border-red-400 focus:ring-red-500/15' : 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15');
    $bills = $this->bills;
    $sum = array_sum(array_map(fn ($v) => max(0, (int) $v), $alloc));
    $remainingAll = array_sum(array_column($bills, 'remaining'));
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs text-stone-500"><a href="{{ route('pengadaan.hutang') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Hutang</a> / Bayar</p>
                <h1 class="text-lg font-semibold">Bayar hutang supplier</h1>
            </div>
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">Data contoh</span>
        </div>
        <x-pengadaan.nav active="pengadaan.hutang" />
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="min-w-0 space-y-5">
            <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="pf-sup">
                <h2 id="pf-sup" class="font-semibold">Supplier</h2>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @forelse ($this->suppliers as $s)
                        <label wire:key="ps-{{ $s['id'] }}" class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-stone-300 px-3 py-2.5 text-sm has-checked:border-emerald-600 has-checked:bg-emerald-50">
                            <input type="radio" wire:model.live="supplierId" value="{{ $s['id'] }}" class="mt-0.5 accent-emerald-700" @checked((string) $s['id'] === $supplierId)>
                            <span><span class="block font-medium">{{ $s['name'] }}</span><span class="text-xs text-stone-500">{{ $s['count'] }} tagihan · sisa {{ $rp($s['remaining']) }}</span></span>
                        </label>
                    @empty
                        <p class="text-sm text-stone-500">Tidak ada hutang yang belum lunas.</p>
                    @endforelse
                </div>
                @error('supplierId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </section>

            @if ($bills)
                <section class="rounded-2xl border border-stone-200 bg-white" aria-labelledby="pf-bills">
                    <div class="px-5 pt-5">
                        <h2 id="pf-bills" class="font-semibold">Tagihan dibayar</h2>
                        <p class="text-xs text-stone-500">Jumlah dibagi otomatis mulai jatuh tempo terdekat. Ubah per tagihan bila perlu.</p>
                    </div>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[34rem] text-sm">
                            <thead class="bg-stone-50 text-left text-xs text-stone-500">
                                <tr><th class="w-10 px-5 py-2"><span class="sr-only">Pilih</span></th><th class="px-2 py-2 font-medium">Tagihan</th><th class="px-3 py-2 font-medium">Jatuh tempo</th><th class="px-3 py-2 text-right font-medium">Sisa</th><th class="px-5 py-2 font-medium">Dibayar</th></tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($bills as $b)
                                    @php $days = Payables::daysLeft($b); $a = (int) ($alloc[$b['id']] ?? 0); @endphp
                                    <tr wire:key="pb-{{ $b['id'] }}">
                                        <td class="px-5 py-2"><input type="checkbox" wire:model.live="selected" value="{{ $b['id'] }}" @checked(in_array($b['id'], $selected)) aria-label="Bayar {{ $b['po_number'] }}" class="size-4 accent-emerald-700"></td>
                                        <td class="px-2 py-2 font-mono text-xs">{{ $b['po_number'] }}</td>
                                        <td @class(['px-3 py-2 whitespace-nowrap', 'font-semibold text-red-700' => $days < 0])>{{ $date($b['due_at']) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $rp($b['remaining']) }}</td>
                                        <td class="px-5 py-2">
                                            <span class="flex items-center gap-2">
                                                <input type="number" min="0" max="{{ $b['remaining'] }}" step="1000" wire:model.live.debounce.400ms="alloc.{{ $b['id'] }}" value="{{ $alloc[$b['id']] ?? 0 }}" aria-label="Jumlah untuk {{ $b['po_number'] }}" class="{{ $field('alloc.'.$b['id']) }} w-32 tabular-nums">
                                                @if ($a >= $b['remaining'])<span class="text-xs font-semibold text-emerald-800">lunas</span>@elseif ($a > 0)<span class="text-xs text-amber-800">sisa {{ $rp($b['remaining'] - $a) }}</span>@endif
                                            </span>
                                            @error('alloc.'.$b['id']) <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="grid gap-4 border-t border-stone-100 p-5 sm:grid-cols-2">
                        <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Tanggal bayar</span>
                            <input type="date" wire:model="paidAt" value="{{ $paidAt }}" max="{{ now()->toDateString() }}" class="{{ $field('paidAt') }} w-full">
                            @error('paidAt') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <fieldset class="text-sm"><legend class="mb-1 font-medium text-stone-700">Cara bayar</legend>
                            <div class="flex gap-2">
                                @foreach (Payables::METHODS as $key => $label)
                                    <label class="cursor-pointer rounded-full border border-stone-300 px-3 py-1.5 has-checked:border-emerald-700 has-checked:bg-emerald-900 has-checked:text-white">
                                        <input type="radio" wire:model.live="method" value="{{ $key }}" class="sr-only" @checked($method === $key)> {{ $label }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        @if ($method === 'transfer')
                            <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">No. bukti transfer <span class="font-normal text-stone-500">(opsional)</span></span>
                                <input type="text" wire:model="reference" value="{{ $reference }}" maxlength="50" class="{{ $field('reference') }} w-full"></label>
                        @endif
                        <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Catatan <span class="font-normal text-stone-500">(opsional)</span></span>
                            <input type="text" wire:model="note" value="{{ $note }}" maxlength="200" class="{{ $field('note') }} w-full"></label>
                    </div>
                </section>
            @endif
        </div>

        <aside class="h-fit space-y-3 xl:sticky xl:top-4">
            <div class="rounded-2xl border border-stone-200 bg-white p-5">
                <h2 class="font-semibold">Jumlah dibayar</h2>
                <label class="mt-3 block text-sm"><span class="sr-only">Jumlah dibayar</span>
                    <span class="relative block"><span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-stone-500">Rp</span>
                        <input type="number" min="0" step="1000" wire:model.live.debounce.400ms="total" value="{{ $total }}" @disabled(! $bills) class="{{ $field('total') }} w-full pl-9 text-lg font-semibold tabular-nums"></span>
                    @error('total') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                @if ($bills)
                    <div class="mt-2 flex gap-1.5 text-xs">
                        <button type="button" wire:click="$set('total', {{ $remainingAll }})" class="rounded-lg border border-stone-300 px-2 py-1 hover:bg-stone-50">Lunasi semua</button>
                        <button type="button" wire:click="$set('total', {{ (int) round($remainingAll / 2, -3) }})" class="rounded-lg border border-stone-300 px-2 py-1 hover:bg-stone-50">Setengah</button>
                    </div>
                    <dl class="mt-4 space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-stone-500">Dialokasikan</dt><dd class="tabular-nums">{{ $rp($sum) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-stone-500">Sisa hutang setelah bayar</dt><dd class="font-semibold tabular-nums">{{ $rp($remainingAll - $sum) }}</dd></div>
                    </dl>
                    @if ((int) $total > $sum)
                        <p class="mt-2 text-xs text-amber-800">Melebihi sisa tagihan terpilih; kelebihan {{ $rp((int) $total - $sum) }} tidak dicatat.</p>
                    @endif
                @endif
                <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" @disabled(! $bills)
                        class="mt-4 w-full rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">Simpan pembayaran</button>
                <a href="{{ route('pengadaan.hutang') }}" wire:navigate class="mt-2 block text-center text-sm font-medium text-stone-500 hover:text-stone-800">Batal</a>
            </div>
        </aside>
    </div>
</div>
