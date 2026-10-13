@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $signed = fn ($n) => ($n > 0 ? '+' : ($n < 0 ? '−' : '')).$rp(abs((int) $n));
    [$from, $to] = $this->window;
    $barTone = [
        'promo' => ['aktif' => 'bg-emerald-600 text-white', 'terjadwal' => 'bg-sky-500 text-white', 'berakhir' => 'bg-stone-300 text-stone-700', 'nonaktif' => 'bg-stone-100 text-stone-500 ring-1 ring-dashed ring-stone-300'],
        'kupon' => ['aktif' => 'bg-amber-400 text-amber-950', 'terjadwal' => 'bg-amber-200 text-amber-900', 'habis' => 'bg-red-200 text-red-900', 'berakhir' => 'bg-stone-300 text-stone-700', 'nonaktif' => 'bg-stone-100 text-stone-500'],
    ];
    $statusLabel = ['aktif' => 'Berlaku', 'terjadwal' => 'Terjadwal', 'berakhir' => 'Berakhir', 'nonaktif' => 'Nonaktif', 'habis' => 'Kuota habis'];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Jadwal & Dampak Promo</h1>
                <p class="text-xs text-stone-500">{{ $from->translatedFormat('d M Y') }} – {{ $to->translatedFormat('d M Y') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <div class="flex items-center rounded-xl border border-stone-200 bg-white" role="group" aria-label="Geser linimasa">
                    <button type="button" wire:click="move(-1)" class="grid size-9 place-items-center rounded-l-xl text-stone-500 hover:bg-stone-50 hover:text-stone-800" aria-label="4 minggu sebelumnya">
                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                    </button>
                    <button type="button" wire:click="resetWindow" class="h-9 border-x border-stone-200 px-3 text-sm font-medium text-stone-700 hover:bg-stone-50">Hari ini</button>
                    <button type="button" wire:click="move(1)" class="grid size-9 place-items-center rounded-r-xl text-stone-500 hover:bg-stone-50 hover:text-stone-800" aria-label="4 minggu berikutnya">
                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <x-promo.nav active="promo.jadwal" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6" wire:loading.class="opacity-60" wire:target="move,resetWindow">
        <p class="rounded-xl border border-dashed border-amber-300 bg-amber-50/60 px-4 py-2 text-xs text-amber-900">Tahap pengembangan: jadwal & angka dampak masih data tiruan.</p>

        {{-- Linimasa --}}
        <section class="rounded-xl border border-stone-200 bg-white" aria-labelledby="timeline-title">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                <h2 id="timeline-title" class="text-sm font-semibold">Linimasa promo & kupon</h2>
                <ul class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-stone-600" aria-label="Keterangan warna">
                    <li class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-emerald-600"></span>Promo berlaku</li>
                    <li class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-sky-500"></span>Promo terjadwal</li>
                    <li class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-amber-400"></span>Kupon</li>
                    <li class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-stone-300"></span>Berakhir/nonaktif</li>
                </ul>
            </div>

            @if ($this->rows === [])
                <p class="px-4 py-10 text-center text-sm text-stone-400">Tidak ada promo atau kupon pada rentang ini.</p>
            @else
                <div class="mt-3 overflow-x-auto pb-3">
                    <div class="min-w-[44rem] px-4">
                        {{-- Sumbu minggu --}}
                        <div class="grid grid-cols-[13rem_1fr] gap-3">
                            <div></div>
                            <div class="relative h-5 text-[11px] text-stone-500">
                                @foreach ($this->weeks as $w)
                                    <span class="absolute top-0 -translate-x-0 border-l border-stone-200 pl-1 tabular-nums" style="left: {{ $w['left'] }}%">{{ $w['label'] }}</span>
                                @endforeach
                            </div>
                        </div>

                        <ul class="divide-y divide-stone-100">
                            @foreach ($this->rows as $r)
                                @php $tone = $barTone[$r['kind']][$r['status']] ?? 'bg-stone-200 text-stone-600'; @endphp
                                <li class="grid grid-cols-[13rem_1fr] items-center gap-3 py-2" wire:key="tl-{{ $r['kind'] }}-{{ $r['id'] }}">
                                    <div class="min-w-0">
                                        <p class="flex items-center gap-1.5 truncate text-sm font-medium" title="{{ $r['name'] }}">
                                            @if ($r['kind'] === 'kupon')<code class="font-mono text-xs font-bold text-amber-900">{{ $r['name'] }}</code>@else{{ $r['name'] }}@endif
                                        </p>
                                        <p class="truncate text-[11px] text-stone-500" title="{{ $r['detail'] }}">{{ $statusLabel[$r['status']] ?? $r['status'] }} · {{ $r['detail'] }}</p>
                                    </div>
                                    <div class="relative h-7 rounded-md bg-stone-50">
                                        @foreach ($this->weeks as $w)
                                            <span class="absolute inset-y-0 border-l border-stone-100" style="left: {{ $w['left'] }}%" aria-hidden="true"></span>
                                        @endforeach
                                        @if ($this->todayAt !== null)
                                            <span class="absolute -inset-y-2 z-10 w-0.5 bg-red-500/70" style="left: {{ $this->todayAt }}%" aria-hidden="true"></span>
                                        @endif
                                        <div @class(['absolute inset-y-1 flex items-center overflow-hidden px-2 text-[11px] font-semibold whitespace-nowrap', $tone,
                                                     'rounded-l-md' => ! $r['clip_start'], 'rounded-r-md' => ! $r['clip_end']])
                                             style="left: {{ $r['left'] }}%; width: {{ $r['width'] }}%"
                                             title="{{ $r['name'] }}: {{ \Illuminate\Support\Carbon::parse($r['starts_at'])->translatedFormat('d M') }} – {{ \Illuminate\Support\Carbon::parse($r['ends_at'])->translatedFormat('d M Y') }}">
                                            <span class="truncate">{{ \Illuminate\Support\Carbon::parse($r['starts_at'])->translatedFormat('d M') }} – {{ \Illuminate\Support\Carbon::parse($r['ends_at'])->translatedFormat('d M') }}</span>
                                        </div>
                                    </div>
                                    @if ($r['clashes'])
                                        <p class="col-start-2 -mt-1 flex items-center gap-1 text-[11px] text-amber-800">
                                            <svg class="size-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                                            Bersamaan dengan {{ implode(', ', $r['clashes']) }} pada barang yang sama — hanya promo paling hemat yang dipakai.
                                        </p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($this->todayAt !== null)
                            <p class="mt-2 flex items-center gap-1.5 text-[11px] text-stone-500"><span class="h-3 w-0.5 bg-red-500/70" aria-hidden="true"></span>Hari ini, {{ today()->translatedFormat('d F Y') }}</p>
                        @endif
                    </div>
                </div>
            @endif
        </section>

        {{-- Dampak --}}
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="impact-title">
            <div class="px-4 pt-4">
                <h2 id="impact-title" class="text-sm font-semibold">Dampak terhadap penjualan</h2>
                <p class="text-xs text-stone-500">Rata-rata penjualan harian barang yang dicakup: 7 hari sebelum promo dibanding selama promo berjalan.</p>
            </div>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[46rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Promo</th>
                            <th class="px-3 py-2 text-right font-medium">Sebelum / hari</th>
                            <th class="px-3 py-2 text-right font-medium">Selama / hari</th>
                            <th class="px-3 py-2 text-right font-medium">Perubahan</th>
                            <th class="px-3 py-2 text-right font-medium">Potongan</th>
                            <th class="px-4 py-2 text-right font-medium">Efek bersih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->impacts as $i)
                            <tr wire:key="impact-{{ $i['id'] }}">
                                <td class="px-4 py-2.5">
                                    <p class="font-medium">{{ $i['name'] }}</p>
                                    <p class="text-xs text-stone-500">{{ $statusLabel[$i['status']] }} · {{ $i['days_running'] }} hari · {{ $i['usage'] }}× dipakai</p>
                                </td>
                                <td class="px-3 py-2.5 text-right text-stone-600 tabular-nums">{{ $rp($i['before_daily']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($i['during_daily']) }}</td>
                                <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                    @if ($i['uplift'] !== null)
                                        <span @class(['inline-flex items-center gap-0.5 font-semibold tabular-nums', 'text-emerald-700' => $i['uplift'] > 0, 'text-red-700' => $i['uplift'] < 0, 'text-stone-500' => $i['uplift'] === 0])>
                                            {{ $i['uplift'] > 0 ? '▲' : ($i['uplift'] < 0 ? '▼' : '') }} {{ abs($i['uplift']) }}%
                                            <span class="sr-only">{{ $i['uplift'] > 0 ? 'naik' : ($i['uplift'] < 0 ? 'turun' : 'tetap') }}</span>
                                        </span>
                                    @else — @endif
                                </td>
                                <td class="px-3 py-2.5 text-right text-stone-600 tabular-nums">{{ $rp($i['discount']) }}</td>
                                <td @class(['px-4 py-2.5 text-right font-semibold tabular-nums', 'text-emerald-800' => $i['net_effect'] > 0, 'text-red-700' => $i['net_effect'] < 0])>{{ $signed($i['net_effect']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-stone-400">Belum ada promo yang berjalan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="border-t border-stone-100 px-4 py-2 text-xs text-stone-500">Efek bersih = tambahan penjualan selama promo − total potongan yang diberikan. Nilai negatif berarti promo lebih banyak memotong daripada menambah penjualan.</p>
        </section>
    </div>
</div>
