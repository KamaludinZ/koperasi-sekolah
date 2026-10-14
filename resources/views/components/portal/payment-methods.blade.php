@props(['payments', 'payment', 'fulfillment', 'balances', 'balanceTotal', 'deduction' => null, 'qrisUrl' => null])
{{-- Pilih metode bayar portal + saldo tiap sumber (tabungan/simpanan) dan rincian potong saldo.
     Dipakai di dalam komponen Livewire yang punya properti `payment` & `savingTypeId`. --}}
@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $b = $balances;
    $option = 'flex cursor-pointer items-start gap-3 rounded-xl border border-stone-300 bg-white px-4 py-3 has-checked:border-emerald-600 has-checked:bg-emerald-50 has-disabled:cursor-not-allowed has-disabled:opacity-50';
    $total = $balanceTotal;
@endphp
    <fieldset class="space-y-2">
        <legend class="mb-1 font-semibold">Pembayaran</legend>
        <label class="{{ $option }}"><input type="radio" wire:model.live="payment" value="tabungan" @disabled($b['tabungan'] < $total) class="mt-1 accent-emerald-700">
            <span><span class="block font-medium">{{ $payments['tabungan'] }}</span><span class="text-sm text-stone-500">Saldo {{ $rp($b['tabungan']) }}{{ $b['tabungan'] < $total ? ' — tidak cukup' : ' · langsung lunas' }}</span></span></label>
        @php $enough = collect($b['simpanan'])->filter(fn ($t) => $t['balance'] >= $total); @endphp
        <label class="{{ $option }}"><input type="radio" wire:model.live="payment" value="simpanan" @disabled($enough->isEmpty()) class="mt-1 accent-emerald-700">
            <span class="flex-1"><span class="block font-medium">{{ $payments['simpanan'] }}</span>
                <span class="text-sm text-stone-500">{{ $b['simpanan'] ? collect($b['simpanan'])->map(fn ($t) => $t['name'].' '.$rp($t['balance']))->join(' · ') : 'Tidak ada simpanan yang bisa dipakai' }}</span>
                @if ($payment === 'simpanan')
                    <select wire:model="savingTypeId" aria-label="Simpanan yang dipotong" class="mt-2 w-full rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm">
                        @foreach ($enough as $t)<option value="{{ $t['id'] }}">{{ $t['name'] }} · saldo {{ $rp($t['balance']) }}</option>@endforeach
                    </select>
                    @error('savingTypeId') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                @endif
            </span></label>
        <label class="{{ $option }}"><input type="radio" wire:model.live="payment" value="tunai" class="mt-1 accent-emerald-700">
            <span><span class="block font-medium">{{ $payments['tunai'] }}</span><span class="text-sm text-stone-500">Bayar saat {{ $fulfillment === 'antar' ? 'barang diantar' : 'mengambil barang' }}.</span></span></label>
        <label class="{{ $option }}"><input type="radio" wire:model.live="payment" value="qris" class="mt-1 accent-emerald-700">
            <span><span class="block font-medium">{{ $payments['qris'] }}</span><span class="text-sm text-stone-500">Pindai QRIS lalu tunjukkan bukti bayar ke petugas.</span></span></label>
        @if ($payment === 'qris' && $qrisUrl)
            <figure class="rounded-xl border border-stone-200 bg-white p-4 text-center">
                <img src="{{ $qrisUrl }}" alt="Kode QRIS koperasi" class="mx-auto max-h-64">
                <figcaption class="mt-2 text-sm text-stone-600">Bayar tepat <strong class="tabular-nums">{{ $rp($total) }}</strong></figcaption>
            </figure>
        @endif
        @if ($d = $deduction)
            <div @class(['rounded-xl border px-4 py-3 text-sm', 'border-emerald-200 bg-emerald-50' => ! $d['short'], 'border-red-200 bg-red-50' => $d['short']]) data-deduction>
                <dl class="space-y-1">
                    <div class="flex justify-between"><dt class="text-stone-600">Saldo {{ $d['source'] }}</dt><dd class="tabular-nums">{{ $rp($d['before']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-600">Dipotong</dt><dd class="tabular-nums">−{{ $rp($d['amount']) }}</dd></div>
                    <div class="flex justify-between border-t border-stone-200 pt-1 font-semibold">
                        <dt>{{ $d['short'] ? 'Kurang' : 'Saldo setelah bayar' }}</dt>
                        <dd @class(['tabular-nums', 'text-red-700' => $d['short']]) data-after>{{ $rp($d['short'] ?: $d['after']) }}</dd>
                    </div>
                </dl>
                <p class="mt-2 text-xs {{ $d['short'] ? 'text-red-800' : 'text-emerald-900' }}">
                    {{ $d['short'] ? 'Saldo tidak cukup — pilih cara bayar lain.' : 'Langsung diproses tanpa menunggu persetujuan pengurus; pesanan otomatis lunas.' }}</p>
            </div>
        @endif
        @error('payment') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
    </fieldset>
