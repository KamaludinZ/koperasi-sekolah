@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $s = $this->summary;
    $h = $this->history;
@endphp
<div class="space-y-5">
    <h1 class="text-xl font-semibold">Simpanan & tabungan</h1>

    <section class="grid gap-3 sm:grid-cols-3" aria-label="Ringkasan saldo">
        <div class="rounded-2xl bg-emerald-900 p-4 text-white sm:col-span-1">
            <p class="text-xs text-emerald-200">Total saldo</p>
            <p class="text-2xl font-bold tabular-nums">{{ $rp($s['tabungan'] + $s['simpanan']) }}</p>
        </div>
        <div class="rounded-2xl border border-stone-200 bg-white p-4">
            <p class="text-xs text-stone-500">Tabungan</p>
            <p class="text-xl font-semibold tabular-nums" data-balance="tabungan">{{ $rp($s['tabungan']) }}</p>
            <p class="text-xs text-stone-500">Bisa dipakai bayar belanja</p>
        </div>
        <div class="rounded-2xl border border-stone-200 bg-white p-4">
            <p class="text-xs text-stone-500">Simpanan</p>
            <p class="text-xl font-semibold tabular-nums" data-balance="simpanan">{{ $rp($s['simpanan']) }}</p>
            <p class="text-xs text-stone-500">Pokok, wajib & sukarela</p>
        </div>
    </section>

    @if ($s['types'])
        <section class="rounded-2xl border border-stone-200 bg-white" aria-labelledby="types-title">
            <h2 id="types-title" class="px-4 pt-4 font-semibold">Simpanan per jenis</h2>
            <ul class="mt-2 divide-y divide-stone-100">
                @foreach ($s['types'] as $t)
                    <li class="px-4 py-3" wire:key="pt-{{ $t['id'] }}">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="font-medium">{{ $t['name'] }}</p>
                            <p class="font-semibold tabular-nums">{{ $rp($t['balance']) }}</p>
                        </div>
                        <p class="mt-1 flex flex-wrap gap-x-2 gap-y-1 text-xs text-stone-500">
                            @if ($t['status'] === 'lunas')
                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 font-medium text-emerald-800">{{ $t['period'] === 'bulanan' ? 'Lancar' : 'Lunas' }}</span>
                            @elseif ($t['status'] === 'belum')
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-900">Belum dibayar {{ $rp(max(0, $t['amount'] - $t['balance'])) }}</span>
                            @elseif ($t['status'] === 'tunggak')
                                <span class="rounded bg-red-100 px-1.5 py-0.5 font-medium text-red-800">Belum disetor {{ $t['arrears_months'] }} bulan · {{ $rp($t['arrears_amount']) }}</span>
                            @endif
                            @if ($t['period'] === 'bulanan' && $t['amount'])<span>{{ $rp($t['amount']) }} / bulan</span>@endif
                            <span>{{ $t['withdrawable'] ? 'Boleh ditarik' : 'Ditahan selama menjadi anggota' }}</span>
                        </p>
                    </li>
                @endforeach
            </ul>
            <p class="px-4 pb-4 text-xs text-stone-500">Setor atau tarik simpanan dilayani pengurus koperasi.</p>
        </section>
    @endif

    <section class="rounded-2xl border border-stone-200 bg-white" aria-labelledby="hist-title">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
            <h2 id="hist-title" class="font-semibold">Riwayat</h2>
            <div class="flex gap-1 rounded-xl bg-stone-100 p-1" role="tablist" aria-label="Jenis riwayat">
                @foreach (['simpanan' => 'Simpanan', 'tabungan' => 'Tabungan'] as $k => $label)
                    <button type="button" role="tab" wire:click="setTab('{{ $k }}')" aria-selected="{{ $tab === $k ? 'true' : 'false' }}"
                            @class(['rounded-lg px-3 py-1 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $tab === $k, 'text-stone-600' => $tab !== $k])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div class="mt-3 flex flex-wrap gap-2 px-4">
            @if ($tab === 'simpanan' && $s['types'])
                <select wire:model.live="typeFilter" aria-label="Jenis simpanan" class="rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm">
                    <option value="">Semua jenis</option>
                    @foreach ($s['types'] as $t)<option value="{{ $t['id'] }}">{{ $t['name'] }}</option>@endforeach
                </select>
            @endif
            <select wire:model.live="period" aria-label="Periode" class="rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm">
                @foreach (\App\Livewire\Portal\Savings::PERIODS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
            @php $tt = $this->totals; @endphp
            <p class="ml-auto self-center text-xs text-stone-500" data-history-totals>Masuk <strong class="text-emerald-800 tabular-nums">+{{ $rp($tt['in']) }}</strong> · Keluar <strong class="text-red-700 tabular-nums">−{{ $rp($tt['out']) }}</strong></p>
        </div>
        <ul class="mt-2 divide-y divide-stone-100">
            @forelse ($h['rows'] as $r)
                <li class="flex items-center justify-between gap-3 px-4 py-2.5" wire:key="h-{{ $r['id'] }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm">{{ $r['label'] }}</p>
                        <p class="text-xs text-stone-500">{{ $r['at']->translatedFormat('d M Y H:i') }}@if ($r['type']) · {{ $r['type'] }}@endif</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p @class(['text-sm font-semibold tabular-nums', 'text-emerald-800' => $r['in'], 'text-red-700' => ! $r['in']])>{{ $r['in'] ? '+' : '−' }}{{ $rp($r['amount']) }}</p>
                        <p class="text-xs text-stone-500 tabular-nums">saldo {{ $rp($r['balance']) }}</p>
                    </div>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-stone-400">Belum ada riwayat {{ $tab }}.</li>
            @endforelse
        </ul>
        @if ($h['more'])
            <div class="px-4 pb-4"><button type="button" wire:click="more" class="w-full rounded-xl border border-stone-300 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Muat lebih banyak</button></div>
        @endif
    </section>
</div>
