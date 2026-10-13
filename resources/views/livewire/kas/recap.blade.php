@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $signed = fn ($n) => ($n > 0 ? '+' : ($n < 0 ? '−' : '')).$rp(abs((int) $n));
    [$from, $to] = $this->range;
    $t = $this->totals;
    $rows = $onlyDifferences ? array_values(array_filter($this->rows, fn ($r) => ! $r['closed_day'] && $r['difference'] !== 0)) : $this->rows;
    $th = 'px-3 py-2 font-medium whitespace-nowrap';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6 print:border-0">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Rekap Kas Harian</h1>
                <p class="text-xs text-stone-500">{{ $from->translatedFormat('d M Y') }} – {{ $to->translatedFormat('d M Y') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 print:hidden">
                <div class="flex gap-1 rounded-xl bg-stone-100 p-1" role="group" aria-label="Periode rekap">
                    @foreach (\App\Livewire\Kas\Recap::PERIODS as $key => $label)
                        <button type="button" wire:click="setPeriod('{{ $key }}')" aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                                @class(['rounded-lg px-3 py-1.5 text-sm font-medium transition',
                                        'bg-white text-stone-900 shadow-sm' => $period === $key,
                                        'text-stone-600 hover:text-stone-900' => $period !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                <button type="button" x-on:click="window.print()" class="inline-flex items-center gap-1.5 rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">
                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a1 1 0 01-1-1v-6a2 2 0 012-2h14a2 2 0 012 2v6a1 1 0 01-1 1h-2M6 14h12v7H6z"/></svg>
                    Cetak
                </button>
            </div>
        </div>
        <div class="print:hidden"><x-kas.nav active="kas.rekap" /></div>
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6" wire:loading.class="opacity-60" wire:target="setPeriod,onlyDifferences">
        <p class="rounded-xl border border-dashed border-amber-300 bg-amber-50/60 px-4 py-2 text-xs text-amber-900 print:hidden">Tahap pengembangan: angka di halaman ini masih data tiruan, belum dari transaksi sungguhan.</p>

        {{-- Total periode --}}
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Total periode">
            <div class="col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 lg:col-span-1">
                <p class="text-xs text-emerald-800">Total penjualan</p>
                <p class="text-2xl font-bold text-emerald-900 tabular-nums">{{ $rp($t['revenue']) }}</p>
                <p class="text-[11px] text-emerald-800/80">{{ $t['transactions'] }} transaksi · {{ $t['days'] }} hari buka</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Tunai / non-tunai</p>
                <p class="text-xl font-bold tabular-nums">{{ $rp($t['cash_sales']) }}</p>
                <p class="text-[11px] text-stone-500">Non-tunai {{ $rp($t['non_cash']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Kas masuk / keluar</p>
                <p class="flex flex-wrap gap-x-2 text-lg font-bold tabular-nums"><span class="text-emerald-800">+{{ $rp($t['cash_in']) }}</span><span class="text-red-700">−{{ $rp($t['cash_out']) }}</span></p>
                <p class="text-[11px] text-stone-500">Di luar penjualan</p>
            </div>
            <div class="col-span-2 rounded-xl border border-stone-200 bg-white px-4 py-3 lg:col-span-1">
                <p class="text-xs text-stone-500">Selisih kas bersih</p>
                <p @class(['text-xl font-bold tabular-nums', 'text-red-700' => $t['difference'] < 0, 'text-amber-800' => $t['difference'] > 0])>{{ $signed($t['difference']) }}</p>
                <p class="text-[11px] text-stone-500">Kurang {{ $rp(abs($t['short'])) }} ({{ $t['days_short'] }} hari) · lebih {{ $rp($t['over']) }} ({{ $t['days_over'] }} hari)</p>
            </div>
        </section>

        {{-- Tabel per hari --}}
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="recap-title">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                <h2 id="recap-title" class="text-sm font-semibold">Rincian per hari</h2>
                <label class="flex cursor-pointer items-center gap-2 text-sm text-stone-700 print:hidden">
                    <input type="checkbox" wire:model.live="onlyDifferences" class="size-4 rounded accent-emerald-700">
                    Hanya hari dengan selisih
                </label>
            </div>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[50rem] text-sm">
                    <thead class="bg-stone-50 text-xs text-stone-500">
                        <tr>
                            <th class="{{ $th }} pl-4 text-left">Tanggal</th>
                            <th class="{{ $th }} text-left">Kasir</th>
                            <th class="{{ $th }} text-right">Tunai</th>
                            <th class="{{ $th }} text-right">Non-tunai</th>
                            <th class="{{ $th }} text-right">Masuk</th>
                            <th class="{{ $th }} text-right">Keluar</th>
                            <th class="{{ $th }} text-right">Seharusnya</th>
                            <th class="{{ $th }} text-right">Dihitung</th>
                            <th class="{{ $th }} pr-4 text-right">Selisih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($rows as $r)
                            @php $d = \Illuminate\Support\Carbon::parse($r['date']); @endphp
                            <tr wire:key="rc-{{ $r['date'] }}" @class(['bg-red-50/40' => $r['difference'] < 0])>
                                <td class="px-3 py-2 pl-4 whitespace-nowrap">
                                    <a href="{{ route('kas', ['tanggal' => $d->isToday() ? null : $r['date']]) }}" wire:navigate class="font-medium text-emerald-800 hover:underline">{{ $d->translatedFormat('D, d M') }}</a>
                                </td>
                                @if ($r['closed_day'])
                                    <td colspan="8" class="px-3 py-2 pr-4 text-right text-stone-400">Tutup</td>
                                @elseif ($r['shifts'] === 0)
                                    <td colspan="8" class="px-3 py-2 pr-4 text-right text-stone-400">Belum ada shift</td>
                                @else
                                    <td class="max-w-32 truncate px-3 py-2 text-stone-600" title="{{ implode(', ', $r['cashiers']) }}">{{ implode(', ', $r['cashiers']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $rp($r['cash_sales']) }}</td>
                                    <td class="px-3 py-2 text-right text-stone-500 tabular-nums">{{ $rp($r['non_cash']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $r['cash_in'] ? $rp($r['cash_in']) : '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $r['cash_out'] ? $rp($r['cash_out']) : '—' }}</td>
                                    <td class="px-3 py-2 text-right font-medium tabular-nums">{{ $rp($r['expected']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $r['open_shifts'] ? 'Berjalan' : $rp($r['counted']) }}</td>
                                    <td @class(['px-3 py-2 pr-4 text-right font-semibold tabular-nums whitespace-nowrap', 'text-red-700' => $r['difference'] < 0, 'text-amber-800' => $r['difference'] > 0, 'text-emerald-800' => $r['difference'] === 0 && ! $r['open_shifts']])>
                                        @if ($r['open_shifts'] && $r['open_shifts'] === $r['shifts'])
                                            <span class="font-normal text-stone-400">—</span>
                                        @else
                                            {{ $r['difference'] === 0 ? 'Pas' : $signed($r['difference']) }}
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-8 text-center text-stone-400">Tidak ada hari dengan selisih kas pada periode ini.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($rows !== [])
                        <tfoot class="border-t-2 border-stone-200 bg-stone-50 font-semibold">
                            @php $open = collect($rows)->where('closed_day', false); @endphp
                            <tr>
                                <td class="px-3 py-2 pl-4" colspan="2">Total {{ $onlyDifferences ? 'hari berselisih' : 'periode' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $rp($open->sum('cash_sales')) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $rp($open->sum('non_cash')) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $rp($open->sum('cash_in')) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $rp($open->sum('cash_out')) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $rp($open->sum('expected')) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $rp($open->sum('counted')) }}</td>
                                <td @class(['px-3 py-2 pr-4 text-right tabular-nums', 'text-red-700' => $open->sum('difference') < 0, 'text-amber-800' => $open->sum('difference') > 0])>{{ $signed($open->sum('difference')) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </section>
        <p class="text-xs text-stone-500">Selisih = uang dihitung saat tutup shift − kas seharusnya. Shift yang masih berjalan belum dihitung selisihnya.</p>
    </div>
</div>
