@use('App\Livewire\Transaksi\Returns')
@use('App\Livewire\Transaksi\ReturnHistory')
@php
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    $sum = $this->summary;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Riwayat Transaksi</h1>
                <p class="text-xs text-stone-500">Retur dan pembatalan yang sudah disetujui atau ditolak.</p>
            </div>
            <a href="{{ $this->exportUrl }}" class="inline-flex items-center gap-2 rounded-xl border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12m0 0-4-4m4 4 4-4M4 20h16"/></svg>
                Unduh CSV
            </a>
        </div>
        <x-transaksi.nav active="transaksi.retur.riwayat" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <section class="rounded-xl border border-stone-200 bg-white p-3 sm:p-4" aria-label="Filter">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex gap-1.5 overflow-x-auto pb-1 lg:pb-0" role="group" aria-label="Periode cepat">
                    @foreach (ReturnHistory::PRESETS as $key => $label)
                        <button type="button" wire:click="applyPreset('{{ $key }}')" aria-pressed="{{ $preset === $key ? 'true' : 'false' }}"
                                @class(['shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium transition', 'bg-emerald-900 text-white' => $preset === $key, 'border border-stone-300 bg-white text-stone-700 hover:border-stone-400' => $preset !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <label class="text-xs text-stone-600"><span class="mb-1 block">Dari</span>
                        <input type="date" wire:model.live="from" max="{{ now()->toDateString() }}" class="rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm"></label>
                    <label class="text-xs text-stone-600"><span class="mb-1 block">Sampai</span>
                        <input type="date" wire:model.live="to" max="{{ now()->toDateString() }}" class="rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm"></label>
                    <select wire:model.live="kind" aria-label="Jenis" class="rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm">
                        @foreach (Returns::KINDS as $v => $label)<option value="{{ $v }}">{{ $label }}</option>@endforeach
                    </select>
                    <select wire:model.live="outcome" aria-label="Hasil" class="rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm">
                        @foreach (ReturnHistory::OUTCOMES as $v => $label)<option value="{{ $v }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
            </div>
            @error('to') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
            @if ($this->rangeLabel)<p class="mt-2 text-xs text-stone-500">Periode: {{ $this->rangeLabel }}</p>@endif
        </section>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Dana dikembalikan</p>
                <p class="text-xl font-bold tabular-nums">{{ $rp($sum['refunded']) }}</p>
                <p class="text-xs text-stone-500">{{ $sum['approved'] }} disetujui</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Ditolak</p>
                <p class="text-2xl font-bold tabular-nums">{{ $sum['rejected'] }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Kembali ke stok</p>
                <p class="text-2xl font-bold tabular-nums">{{ $sum['restocked'] }} <span class="text-sm font-normal text-stone-500">unit</span></p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Rusak / dibuang</p>
                <p class="text-2xl font-bold tabular-nums">{{ $sum['written_off'] }} <span class="text-sm font-normal text-stone-500">unit</span></p>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
            <section class="space-y-4" aria-label="Riwayat per tanggal">
                @forelse ($this->days as $day => $items)
                    <div wire:key="day-{{ $day }}" class="overflow-hidden rounded-xl border border-stone-200 bg-white">
                        <h2 class="flex justify-between bg-stone-50 px-4 py-2 text-xs font-semibold text-stone-600">
                            <span>{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('l, d F Y') }}</span>
                            <span class="tabular-nums">{{ count($items) }} pengajuan</span>
                        </h2>
                        <ul class="divide-y divide-stone-100">
                            @foreach ($items as $r)
                                <li wire:key="h-{{ $r['id'] }}" class="flex items-start justify-between gap-3 px-4 py-2.5 text-sm">
                                    <span class="min-w-0">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="font-mono text-xs font-semibold">{{ $r['number'] }}</span>
                                            <span class="rounded-md px-2 py-0.5 text-[11px] font-semibold {{ Returns::STATUS_TONES[$r['status']] }}">{{ Returns::STATUSES[$r['status']] }}</span>
                                            <span class="text-xs text-stone-500">{{ $r['kind'] === 'batal' ? 'Pembatalan' : 'Retur' }}</span>
                                        </span>
                                        <span class="block text-stone-700">{{ $r['reason'] }} · {{ collect($r['items'])->map(fn ($i) => $i['qty'].'× '.$i['name'])->join(', ') }}</span>
                                        <span class="block text-xs text-stone-500">
                                            <a href="{{ route('transaksi.show', $r['transaction']['id']) }}" wire:navigate class="font-mono hover:text-emerald-800 hover:underline">{{ $r['transaction']['invoice'] }}</a>
                                            · diajukan {{ $r['requested_by'] ?? '—' }} · diputus {{ $r['decided_by'] ?? 'otomatis' }} {{ \Illuminate\Support\Carbon::parse($r['decided_at'])->format('H:i') }}
                                        </span>
                                        @if ($r['decision_note'])<span class="block text-xs text-red-700">{{ $r['decision_note'] }}</span>@endif
                                    </span>
                                    <span @class(['shrink-0 text-right font-semibold tabular-nums', 'text-stone-400 line-through' => $r['status'] === 'ditolak'])>{{ $rp($r['amount']) }}
                                        @if ($r['status'] === 'disetujui')<span class="block text-xs font-normal text-stone-500">{{ $r['refund_label'] }}</span>@endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p class="rounded-xl border border-stone-200 bg-white px-4 py-10 text-center text-sm text-stone-400">Belum ada retur atau pembatalan yang diputus pada periode ini.</p>
                @endforelse
            </section>

            <aside class="h-fit rounded-xl border border-stone-200 bg-white p-4" aria-labelledby="rh-reasons">
                <h2 id="rh-reasons" class="text-sm font-semibold">Alasan terbanyak (disetujui)</h2>
                @if ($sum['reasons'])
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($sum['reasons'] as $x)
                            <li>
                                <span class="flex justify-between gap-2"><span>{{ $x['reason'] }}</span><span class="tabular-nums">{{ $rp($x['amount']) }}</span></span>
                                <span class="mt-1 block h-1.5 overflow-hidden rounded-full bg-stone-100" aria-hidden="true"><span class="block h-full rounded-full bg-amber-500" style="width: {{ $sum['refunded'] ? round($x['amount'] / $sum['refunded'] * 100) : 0 }}%"></span></span>
                                <span class="text-xs text-stone-500">{{ $x['count'] }} pengajuan</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-2 text-sm text-stone-400">Belum ada data.</p>
                @endif
            </aside>
        </div>
    </div>
</div>
