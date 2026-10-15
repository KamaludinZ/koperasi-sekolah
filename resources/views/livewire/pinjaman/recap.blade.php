@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $r = $this->recap;
    [, , $label] = $this->period;
    $maxMonth = max(1, collect($r['months'])->max(fn ($m) => max($m['disbursed'], $m['collected'])) ?? 1);
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-lg font-semibold">Rekap Pinjaman</h1>
                <p class="text-xs text-stone-500">Pengajuan, pencairan, dan angsuran yang diterima per periode, beserta posisi pinjaman saat ini.</p>
            </div>
            <div class="flex gap-2" data-export-button>
                @foreach (\App\Services\LoanRecapExport::FORMATS as $fmt => $fmtLabel)
                    <button type="button" wire:click="export('{{ $fmt }}')" wire:loading.attr="disabled" wire:target="export" data-export-format="{{ $fmt }}"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-60">
                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0 4-4m-4 4-4-4M4 20h16"/></svg>
                        Ekspor {{ strtoupper($fmt) }}<span class="sr-only"> {{ $label }}</span>
                    </button>
                @endforeach
            </div>
        </div>
        <x-pinjaman.nav active="pinjaman.rekap" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        @php $exports = $this->exports; $busy = collect($exports)->contains(fn ($e) => ! in_array($e['status'], ['done', 'failed'], true)); @endphp
        @if ($exports)
            <section class="rounded-xl border border-stone-200 bg-white" aria-label="Ekspor rekap" @if ($busy) wire:poll.2s @endif aria-live="polite">
                <h2 class="border-b border-stone-100 px-4 py-2.5 text-sm font-semibold">Ekspor terakhir</h2>
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach ($exports as $e)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2" data-export="{{ $e['id'] }}" data-export-status="{{ $e['status'] }}">
                            <span><span class="font-mono text-xs uppercase text-stone-500">{{ $e['format'] }}</span> @tanggal($e['from']) – @tanggal($e['to'])
                                <span @class(['ml-1 rounded-md px-1.5 py-0.5 text-[11px] font-semibold', 'bg-emerald-100 text-emerald-800' => $e['status'] === 'done',
                                    'bg-red-100 text-red-800' => $e['status'] === 'failed', 'bg-stone-100 text-stone-700' => ! in_array($e['status'], ['done', 'failed'], true)])>{{ $e['status_label'] }}</span>
                                @if ($e['status'] === 'failed')<span class="block text-xs text-red-700">{{ $e['error'] }}</span>@endif
                            </span>
                            <span class="flex items-center gap-2">
                                @if ($e['status'] === 'done')
                                    <span class="text-xs text-stone-500" data-download-mark>{{ $e['download_count'] > 0 ? 'Diunduh '.$e['download_count'].'× · terakhir '.\App\Support\Tanggal::format($e['downloaded_at'], true) : 'Belum diunduh' }}</span>
                                    <a href="{{ $e['download_url'] }}" class="rounded-lg bg-emerald-700 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-800">Unduh</a>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <x-pinjaman.recap-cards :portfolio="$r['portfolio']" />
        <h2 class="text-sm font-semibold text-stone-700">Periode: <span data-period>{{ $label }}</span></h2>

        <div class="flex flex-wrap items-center gap-2">
            <div class="flex gap-1 rounded-xl bg-stone-100 p-1" role="tablist" aria-label="Jenis periode">
                @foreach (['bulan' => 'Bulanan', 'tahun' => 'Tahunan', 'rentang' => 'Rentang'] as $k => $t)
                    <button type="button" role="tab" wire:click="setType('{{ $k }}')" aria-selected="{{ $type === $k ? 'true' : 'false' }}"
                            @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-stone-900 shadow-sm' => $type === $k, 'text-stone-600' => $type !== $k])>{{ $t }}</button>
                @endforeach
            </div>
            <div @class(['flex items-center gap-1', 'hidden' => $type === 'rentang'])>
                <button type="button" wire:click="shift(-1)" class="rounded-lg border border-stone-300 px-2.5 py-1.5 text-sm" aria-label="Periode sebelumnya">‹</button>
                <span class="min-w-36 text-center text-sm font-semibold">{{ $label }}</span>
                <button type="button" wire:click="shift(1)" @disabled($this->isCurrent) class="rounded-lg border border-stone-300 px-2.5 py-1.5 text-sm disabled:opacity-40" aria-label="Periode berikutnya">›</button>
            </div>
            @if ($type === 'bulan')
                <input type="month" wire:model.live="month" max="{{ today()->format('Y-m') }}" aria-label="Pilih bulan" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm">
            @elseif ($type === 'rentang')
                <label class="flex items-center gap-1 text-sm">Dari <input type="date" wire:model.live="from" max="{{ today()->toDateString() }}" class="rounded-lg border border-stone-300 bg-white px-2 py-1.5 text-sm"></label>
                <label class="flex items-center gap-1 text-sm">sampai <input type="date" wire:model.live="to" max="{{ today()->toDateString() }}" class="rounded-lg border border-stone-300 bg-white px-2 py-1.5 text-sm"></label>
                <span class="text-xs text-stone-500">maks. {{ \App\Services\LoanRecapService::MAX_RANGE_DAYS }} hari</span>
            @endif
            <div class="flex flex-wrap gap-1" aria-label="Pintasan periode">
                @foreach (\App\Livewire\Pinjaman\Recap::PRESETS as $k => $t)
                    <button type="button" wire:click="preset('{{ $k }}')" class="rounded-full border border-stone-300 px-2.5 py-1 text-xs text-stone-700 hover:bg-stone-50" data-preset="{{ $k }}">{{ $t }}</button>
                @endforeach
            </div>
        </div>

        <section class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Rekap periode">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3" data-recap="pengajuan">
                <p class="text-xs text-stone-500">Pengajuan masuk</p>
                <p class="text-xl font-bold tabular-nums">{{ $r['applications']['count'] }}</p>
                <p class="text-xs text-stone-500">{{ $rp($r['applications']['amount']) }} · {{ $r['applications']['approved'] }} disetujui · {{ $r['applications']['rejected'] }} ditolak</p>
            </div>
            <div class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3" data-recap="pencairan">
                <p class="text-xs text-indigo-900">Dana dicairkan</p>
                <p class="text-xl font-bold tabular-nums text-indigo-950">{{ $rp($r['disbursed']['amount']) }}</p>
                <p class="text-xs text-indigo-900/80">{{ $r['disbursed']['count'] }} pinjaman</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3" data-recap="diterima">
                <p class="text-xs text-emerald-800">Angsuran diterima</p>
                <p class="text-xl font-bold tabular-nums text-emerald-900">{{ $rp($r['collected']['amount']) }}</p>
                <p class="text-xs text-emerald-800/80">{{ $r['collected']['count'] }} pembayaran · pokok {{ $rp($r['collected']['principal']) }} · bunga {{ $rp($r['collected']['interest']) }}</p>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border border-stone-200 bg-white lg:col-span-2" aria-labelledby="by-method">
                <h2 id="by-method" class="border-b border-stone-100 px-4 py-3 text-sm font-semibold">Angsuran diterima per metode bayar · {{ $label }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-sm" data-method-table>
                        <thead class="bg-stone-50 text-left text-xs text-stone-500">
                            <tr>
                                <th class="px-4 py-2 font-medium">Metode</th>
                                <th class="px-3 py-2 text-right font-medium">Pembayaran</th>
                                <th class="px-3 py-2 text-right font-medium">Diterima</th>
                                <th class="px-3 py-2 text-right font-medium">Pokok</th>
                                <th class="px-3 py-2 text-right font-medium">Bunga</th>
                                <th class="px-3 py-2 font-medium">Bagian</th>
                                <th class="px-4 py-2 text-right font-medium">Pinjaman aktif · sisa</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($r['collected']['by_method'] as $m)
                                <tr data-method="{{ $m['method'] }}" @class(['text-stone-400' => $m['count'] === 0])>
                                    <td class="px-4 py-2.5 font-medium">{{ $m['label'] }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $m['count'] }}<span class="block text-xs text-stone-500">{{ $m['loans_paid'] }} pinjaman</span></td>
                                    <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ $rp($m['amount']) }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($m['principal']) }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($m['interest']) }}</td>
                                    <td class="px-3 py-2.5">
                                        <span class="text-xs tabular-nums">{{ str_replace('.', ',', (string) $m['share']) }}%</span>
                                        <span class="mt-1 block h-1.5 w-24 overflow-hidden rounded-full bg-stone-100" aria-hidden="true"><span class="block h-full bg-emerald-600" style="width: {{ min(100, $m['share']) }}%"></span></span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $m['active_loans'] }} · {{ $rp($m['active_outstanding']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-stone-200 bg-stone-50 font-semibold">
                            <tr data-method-total>
                                <td class="px-4 py-2.5">Total</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $r['collected']['count'] }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($r['collected']['amount']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($r['collected']['principal']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($r['collected']['interest']) }}</td>
                                <td class="px-3 py-2.5 text-xs">{{ $r['collected']['amount'] > 0 ? '100%' : '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ $r['portfolio']['active'] }} · {{ $rp($r['portfolio']['outstanding']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            @if ($r['months'])
                <section class="rounded-xl border border-stone-200 bg-white" aria-labelledby="per-month">
                    <h2 id="per-month" class="border-b border-stone-100 px-4 py-3 text-sm font-semibold">Per bulan</h2>
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-stone-500"><tr><th class="px-4 py-2 font-medium">Bulan</th><th class="px-3 py-2 text-right font-medium">Dicairkan</th><th class="px-4 py-2 text-right font-medium">Diterima</th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($r['months'] as $m)
                                <tr data-month="{{ $m['month'] }}">
                                    <td class="px-4 py-2">{{ $m['label'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $m['disbursed'] ? $rp($m['disbursed']) : '—' }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">
                                        {{ $m['collected'] ? $rp($m['collected']) : '—' }}
                                        <span class="mt-1 block h-1 rounded-full bg-emerald-500" style="width: {{ intdiv($m['collected'] * 100, $maxMonth) }}%" aria-hidden="true"></span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif
        </div>

        @if ($r['applications']['count'] === 0 && $r['disbursed']['count'] === 0 && $r['collected']['count'] === 0)
            <p class="rounded-xl border border-dashed border-stone-300 bg-white px-4 py-8 text-center text-sm text-stone-500" data-empty="rekap">Belum ada kegiatan pinjaman pada {{ $label }}.</p>
        @endif
    </div>
</div>
