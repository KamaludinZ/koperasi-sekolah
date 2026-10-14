@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $l = $this->list;
    $tt = $this->totals;
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold">Riwayat belanja</h1>
            <p class="text-sm text-stone-500"><span class="tabular-nums">{{ $tt['count'] }}</span> transaksi · total <strong class="text-stone-900 tabular-nums">{{ $rp($tt['spent']) }}</strong></p>
        </div>
        <select wire:model.live="period" aria-label="Periode" class="rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm">
            @foreach (\App\Livewire\Portal\Purchases::PERIODS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    <ul class="divide-y divide-stone-100 overflow-hidden rounded-2xl border border-stone-200 bg-white">
        @forelse ($l['rows'] as $r)
            <li wire:key="tx-{{ $r['id'] }}">
                <button type="button" wire:click="show({{ $r['id'] }})" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-stone-50">
                    <span class="min-w-0">
                        <span class="block font-mono text-xs text-stone-500">{{ $r['invoice'] }}</span>
                        <span class="block text-sm">{{ $r['at']->translatedFormat('d M Y H:i') }} · {{ $r['items'] }} barang · {{ $r['method'] }}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span @class(['block font-semibold tabular-nums', 'text-stone-400 line-through' => $r['voided']])>{{ $rp($r['total']) }}</span>
                        @if ($r['voided'])<span class="text-xs font-medium text-red-700">Dibatalkan</span>
                        @elseif ($r['returned'])<span class="text-xs font-medium text-amber-800">Retur {{ $rp($r['returned']) }}</span>@endif
                    </span>
                </button>
            </li>
        @empty
            <li class="px-4 py-10 text-center text-sm text-stone-400">Belum ada belanja pada periode ini.</li>
        @endforelse
    </ul>
    @if ($l['more'])
        <button type="button" wire:click="more" class="w-full rounded-xl border border-stone-300 bg-white py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Muat lebih banyak</button>
    @endif

    @if ($d = $this->detail)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-stone-950/50 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="tx-title"
             x-data x-on:keydown.escape.window="$wire.close()" wire:click.self="close">
            <div class="max-h-[85dvh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div>
                        <h2 id="tx-title" class="font-mono text-sm font-semibold">{{ $d['invoice'] }}</h2>
                        <p class="text-xs text-stone-500">{{ $d['at']->translatedFormat('l, d F Y H:i') }} · {{ $d['method'] }}</p>
                    </div>
                    <button type="button" wire:click="close" class="text-stone-400 hover:text-stone-600" aria-label="Tutup"><svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
                </div>
                <div class="space-y-3 px-5 py-4 text-sm">
                    @if ($d['voided'])<p class="rounded-lg bg-red-50 px-3 py-2 text-red-800">Transaksi ini dibatalkan{{ $d['void_reason'] ? ': '.$d['void_reason'] : '' }}.</p>@endif
                    <ul class="divide-y divide-stone-100">
                        @foreach ($d['items'] as $i)
                            <li class="flex justify-between gap-3 py-2"><span>{{ $i['name'] }} <span class="block text-xs text-stone-500">{{ $i['qty'] }} × {{ $rp($i['price']) }}</span></span><span class="tabular-nums">{{ $rp($i['subtotal']) }}</span></li>
                        @endforeach
                    </ul>
                    <dl class="space-y-1 border-t border-stone-200 pt-2">
                        <div class="flex justify-between"><dt class="text-stone-500">Subtotal</dt><dd class="tabular-nums">{{ $rp($d['subtotal']) }}</dd></div>
                        @if ($d['discount'])<div class="flex justify-between"><dt class="text-stone-500">Diskon</dt><dd class="tabular-nums">−{{ $rp($d['discount']) }}</dd></div>@endif
                        @if ($d['tax'])<div class="flex justify-between"><dt class="text-stone-500">Pajak</dt><dd class="tabular-nums">{{ $rp($d['tax']) }}</dd></div>@endif
                        @if ($d['rounding'])<div class="flex justify-between"><dt class="text-stone-500">Pembulatan</dt><dd class="tabular-nums">{{ $rp($d['rounding']) }}</dd></div>@endif
                        <div class="flex justify-between text-base font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ $rp($d['total']) }}</dd></div>
                        @if ($d['returned'])<div class="flex justify-between text-amber-800"><dt>Dikembalikan (retur)</dt><dd class="tabular-nums">−{{ $rp($d['returned']) }}</dd></div>@endif
                    </dl>
                </div>
            </div>
        </div>
    @endif
</div>
