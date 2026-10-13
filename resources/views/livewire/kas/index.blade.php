@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $signed = fn ($n) => ($n > 0 ? '+' : ($n < 0 ? '−' : '')).$rp(abs((int) $n));
    $c = $this->cash;
    $s = $c['summary'];
    $day = $this->day;
    // Shift terbuka (hari ini: shift yang sedang berjalan, walau dibuka hari sebelumnya).
    $open = $c['is_today'] ? $this->openShiftRow : collect($c['shifts'])->firstWhere('status', 'open');
    $openEarlier = $open && \Illuminate\Support\Carbon::parse($open['opened_at'])->lt($day->copy()->startOfDay());
    $primary = 'inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-semibold bg-emerald-800 text-white hover:bg-emerald-900';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Kas Harian</h1>
                <p class="text-xs text-stone-500">{{ $day->translatedFormat('l, d F Y') }}@if ($c['is_today']) · hari ini @endif</p>
            </div>
            <div class="flex items-center gap-2">
                <div class="flex items-center rounded-xl border border-stone-200 bg-white" role="group" aria-label="Pilih tanggal">
                    <button type="button" wire:click="shiftDay(-1)" class="grid size-9 place-items-center rounded-l-xl text-stone-500 hover:bg-stone-50 hover:text-stone-800" aria-label="Hari sebelumnya">
                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                    </button>
                    <label for="kas-date" class="sr-only">Tanggal</label>
                    <input id="kas-date" type="date" wire:key="kas-date-{{ $day->toDateString() }}" x-on:change="$wire.set('date', $event.target.value)" value="{{ $day->toDateString() }}" max="{{ today()->toDateString() }}"
                           class="h-9 border-x border-stone-200 px-2 text-sm tabular-nums outline-none focus:bg-emerald-50">
                    <button type="button" wire:click="shiftDay(1)" @disabled($c['is_today']) class="grid size-9 place-items-center rounded-r-xl text-stone-500 hover:bg-stone-50 hover:text-stone-800 disabled:cursor-not-allowed disabled:opacity-40" aria-label="Hari berikutnya">
                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </button>
                </div>
                @unless ($c['is_today'])
                    <button type="button" wire:click="goToday" class="rounded-xl px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">Hari ini</button>
                @endunless
            </div>
        </div>
        <x-kas.nav active="kas" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6" wire:loading.class="opacity-60" wire:target="shiftDay,goToday,date">
        @if ($openEarlier)
            <p class="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900" role="alert">
                <svg class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                <span>{{ $open['name'] }} sejak {{ \Illuminate\Support\Carbon::parse($open['opened_at'])->translatedFormat('l, d M · H:i') }} belum ditutup. Hitung uang laci lalu tutup shift sebelum memulai shift baru.</span>
            </p>
        @endif
        @if (($c['unassigned_cash_sales'] ?? 0) > 0)
            <p class="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
                <svg class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4m0 4h.01M12 21a9 9 0 100-18 9 9 0 000 18z"/></svg>
                <span>Ada penjualan tunai {{ $rp($c['unassigned_cash_sales']) }} saat tidak ada shift terbuka — belum masuk hitungan kas laci mana pun.</span>
            </p>
        @endif

        {{-- Status shift --}}
        @if ($c['closed_day'])
            <section class="rounded-2xl border border-stone-200 bg-white px-5 py-8 text-center">
                <p class="font-semibold">Koperasi tutup</p>
                <p class="mt-1 text-sm text-stone-500">Hari ini koperasi tidak buka menurut jadwal, jadi tidak ada shift kasir.</p>
            </section>
        @elseif ($c['shifts'] === [] && ! $open)
            <section class="rounded-2xl border border-stone-200 bg-white px-5 py-8 text-center">
                <p class="font-semibold">Belum ada shift dibuka</p>
                <p class="mt-1 text-sm text-stone-500">Kas laci mulai tercatat setelah kasir membuka shift dengan modal awal.</p>
                @if ($c['is_today'] && $this->canOperate)
                    <div class="mt-4 flex justify-center"><button type="button" wire:click="openDialog('buka')" class="{{ $primary }}">Buka shift</button></div>
                @endif
            </section>
        @else
            <section @class(['relative isolate overflow-hidden rounded-2xl p-5',
                             'bg-emerald-950 text-emerald-50' => $open,
                             'border border-stone-200 bg-white' => ! $open]) aria-labelledby="kas-status">
                @if ($open)<div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.08]" aria-hidden="true"></div>@endif
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="min-w-0">
                        @if ($open)
                            <p class="flex items-center gap-2 text-xs font-semibold tracking-wide text-amber-300 uppercase" id="kas-status">
                                <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-amber-300 opacity-75"></span><span class="relative inline-flex size-2 rounded-full bg-amber-300"></span></span>
                                Shift sedang berjalan
                            </p>
                            <p class="mt-1 font-display text-2xl font-bold text-white">{{ $open['name'] }} · {{ $open['cashier'] }}</p>
                            <p class="text-sm text-emerald-200">Dibuka {{ \Illuminate\Support\Carbon::parse($open['opened_at'])->format('H:i') }} dengan modal {{ $rp($open['opening_cash']) }} · {{ $open['transactions'] }} transaksi sejauh ini</p>
                        @else
                            <p class="text-xs font-semibold tracking-wide text-emerald-700 uppercase" id="kas-status">Semua shift sudah ditutup</p>
                            <p class="mt-1 font-display text-2xl font-bold text-emerald-950">{{ $s['closed_shifts'] }} shift · {{ $c['sales']['count'] }} transaksi</p>
                            <p class="text-sm text-stone-600">Uang laci terhitung {{ $rp($s['counted']) }} dari seharusnya {{ $rp($s['expected']) }}.</p>
                        @endif
                    </div>
                    @if ($c['is_today'] && $this->canOperate && (! $open || $this->canHandleShift))
                        <div class="flex flex-wrap gap-2">
                            @if ($open)
                                <button type="button" wire:click="openDialog('kas')" class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-700 px-3.5 py-2 text-sm font-semibold text-emerald-50 hover:bg-emerald-900">Setor / tarik kas</button>
                                <button type="button" wire:click="openDialog('tutup')" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-semibold bg-amber-400 text-emerald-950 hover:bg-amber-300">Tutup shift</button>
                            @else
                                <button type="button" wire:click="openDialog('buka')" class="{{ $primary }}">Buka shift baru</button>
                            @endif
                        </div>
                    @endif
                </div>
            </section>
        @endif

        {{-- Angka utama --}}
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Ringkasan kas">
            <div class="col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 lg:col-span-1">
                <p class="text-xs text-emerald-800">Kas seharusnya di laci</p>
                <p class="text-2xl font-bold text-emerald-900 tabular-nums">{{ $rp($s['expected']) }}</p>
                <p class="text-[11px] text-emerald-800/80">Modal {{ $rp($s['opening_cash']) }} + tunai + kas masuk − keluar</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Penjualan tunai</p>
                <p class="text-xl font-bold tabular-nums">{{ $rp($s['cash_sales']) }}</p>
                <p class="text-[11px] text-stone-500">Non-tunai {{ $rp($s['non_cash']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Kas masuk / keluar</p>
                <p class="text-xl font-bold tabular-nums"><span class="text-emerald-800">+{{ $rp($s['cash_in']) }}</span> <span class="text-base text-stone-400">/</span> <span class="text-red-700">−{{ $rp($s['cash_out']) }}</span></p>
                <p class="text-[11px] text-stone-500">{{ count($c['movements']) }} catatan setoran & penarikan</p>
            </div>
            <div class="col-span-2 rounded-xl border border-stone-200 bg-white px-4 py-3 lg:col-span-1">
                <p class="text-xs text-stone-500">Selisih kas</p>
                <p @class(['text-xl font-bold tabular-nums', 'text-red-700' => $s['difference'] < 0, 'text-amber-800' => $s['difference'] > 0])>{{ $signed($s['difference']) }}</p>
                <p class="text-[11px] text-stone-500">{{ $s['closed_shifts'] }} shift ditutup{{ $s['open_shifts'] ? ', '.$s['open_shifts'].' masih berjalan' : '' }}</p>
            </div>
        </section>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            {{-- Shift hari ini --}}
            <section aria-labelledby="kas-shifts">
                <h2 id="kas-shifts" class="mb-2 text-sm font-semibold text-stone-700">Shift kasir</h2>
                @forelse ($c['shifts'] as $shift)
                    @php
                        $opened = \Illuminate\Support\Carbon::parse($shift['opened_at']);
                        $closed = $shift['closed_at'] ? \Illuminate\Support\Carbon::parse($shift['closed_at']) : null;
                    @endphp
                    <article class="mb-3 rounded-xl border border-stone-200 bg-white" wire:key="shift-{{ $shift['id'] }}">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-100 px-4 py-3">
                            <div>
                                <p class="font-semibold">{{ $shift['name'] }} <span class="font-normal text-stone-500">· {{ $shift['cashier'] }}</span></p>
                                <p class="text-xs text-stone-500 tabular-nums">{{ $opened->format('H:i') }} – {{ $closed?->format('H:i') ?? 'sekarang' }} · {{ $shift['transactions'] }} transaksi</p>
                            </div>
                            @if ($shift['status'] === 'open')
                                <x-kas.difference-badge running />
                            @else
                                <x-kas.difference-badge :amount="$shift['difference']" />
                            @endif
                        </div>
                        <dl class="grid gap-x-8 px-4 py-3 text-sm sm:grid-cols-2">
                            <div class="space-y-1">
                                <div class="flex justify-between gap-3"><dt class="text-stone-500">Modal awal</dt><dd class="tabular-nums">{{ $rp($shift['opening_cash']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-stone-500">+ Penjualan tunai</dt><dd class="tabular-nums">{{ $rp($shift['cash_sales']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-stone-500">+ Kas masuk</dt><dd class="tabular-nums">{{ $rp($shift['cash_in']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-stone-500">− Kas keluar</dt><dd class="tabular-nums">{{ $rp($shift['cash_out']) }}</dd></div>
                            </div>
                            <div class="mt-2 space-y-1 border-t border-stone-100 pt-2 sm:mt-0 sm:border-t-0 sm:border-l sm:pt-0 sm:pl-8">
                                <div class="flex justify-between gap-3 font-semibold"><dt>Kas seharusnya</dt><dd class="tabular-nums">{{ $rp($shift['expected']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-stone-500">Uang dihitung</dt><dd class="tabular-nums">{{ $shift['counted'] === null ? '—' : $rp($shift['counted']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-stone-500">Selisih</dt>
                                    <dd @class(['tabular-nums font-semibold', 'text-red-700' => $shift['difference'] < 0, 'text-amber-800' => $shift['difference'] > 0])>{{ $shift['difference'] === null ? '—' : $signed($shift['difference']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-stone-500">Non-tunai</dt><dd class="tabular-nums text-stone-600">{{ $rp($shift['qris_sales'] + $shift['transfer_sales']) }}</dd></div>
                            </div>
                        </dl>
                        @if ($shift['note'])
                            <p class="border-t border-stone-100 px-4 py-2 text-xs text-stone-600"><span class="font-medium">Catatan tutup shift:</span> {{ $shift['note'] }}</p>
                        @endif
                    </article>
                @empty
                    <p class="rounded-xl border border-dashed border-stone-300 px-4 py-6 text-center text-sm text-stone-400">Tidak ada shift pada tanggal ini.</p>
                @endforelse
            </section>

            <div class="space-y-5">
                {{-- Penjualan per metode --}}
                <section class="rounded-xl border border-stone-200 bg-white p-4" aria-labelledby="kas-methods">
                    <h2 id="kas-methods" class="text-sm font-semibold">Penjualan per metode bayar</h2>
                    @php $methods = ['tunai' => 'Tunai', 'qris' => 'QRIS', 'transfer' => 'Transfer']; $max = max(1, $c['sales']['tunai'], $c['sales']['qris'], $c['sales']['transfer']); @endphp
                    <ul class="mt-3 space-y-3">
                        @foreach ($methods as $key => $label)
                            <li>
                                <div class="flex justify-between gap-2 text-sm"><span>{{ $label }}</span><span class="font-semibold tabular-nums">{{ $rp($c['sales'][$key]) }}</span></div>
                                <div class="mt-1 h-1.5 rounded-full bg-stone-100"><div @class(['h-full rounded-full', 'bg-emerald-600' => $key === 'tunai', 'bg-stone-400' => $key !== 'tunai']) style="width: {{ round($c['sales'][$key] / $max * 100) }}%"></div></div>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-xs text-stone-500">Hanya penjualan tunai yang menambah uang di laci. QRIS & transfer masuk ke rekening koperasi.</p>
                </section>

                {{-- Setoran & penarikan --}}
                <section class="rounded-xl border border-stone-200 bg-white" aria-labelledby="kas-moves">
                    <div class="flex items-baseline justify-between gap-2 px-4 pt-4">
                        <h2 id="kas-moves" class="text-sm font-semibold">Setoran & penarikan kas</h2>
                        @if ($open && $c['is_today'] && $this->canHandleShift)
                            <button type="button" wire:click="openDialog('kas')" class="text-sm font-semibold text-emerald-800 hover:underline">+ Catat</button>
                        @endif
                    </div>
                    @if ($c['movements'] === [])
                        <p class="px-4 py-6 text-center text-sm text-stone-400">Tidak ada uang masuk/keluar laci selain penjualan.</p>
                    @else
                        <ul class="mt-2 divide-y divide-stone-100">
                            @foreach ($c['movements'] as $m)
                                <li class="flex items-start justify-between gap-3 px-4 py-2.5 text-sm" wire:key="mv-{{ $m['id'] }}">
                                    <div class="min-w-0">
                                        <p class="truncate">{{ $m['reason'] }}</p>
                                        <p class="text-xs text-stone-500 tabular-nums">{{ \Illuminate\Support\Carbon::parse($m['created_at'])->format('H:i') }} · {{ $m['category_label'] ?? '' }} · {{ $m['cashier'] }}</p>
                                    </div>
                                    <span @class(['shrink-0 font-semibold tabular-nums', 'text-emerald-800' => $m['type'] === 'in', 'text-red-700' => $m['type'] === 'out'])>
                                        <span class="sr-only">{{ $m['type'] === 'in' ? 'Masuk' : 'Keluar' }}</span>{{ $m['type'] === 'in' ? '+' : '−' }}{{ $rp($m['amount']) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </div>

        {{-- 7 hari terakhir --}}
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="kas-week">
            <div class="flex items-baseline justify-between gap-2 px-4 pt-4">
                <h2 id="kas-week" class="text-sm font-semibold">7 hari terakhir</h2>
                <a href="{{ route('kas.rekap') }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:underline">Rekap lengkap →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="mt-2 w-full min-w-[32rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr><th class="px-4 py-2 font-medium">Tanggal</th><th class="px-4 py-2 text-right font-medium">Penjualan</th><th class="px-4 py-2 text-right font-medium">Tunai</th><th class="px-4 py-2 text-right font-medium">Shift</th><th class="px-4 py-2 text-right font-medium">Selisih kas</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach (array_reverse($this->week) as $w)
                            @php $wd = \Illuminate\Support\Carbon::parse($w['date']); @endphp
                            <tr @class(['bg-emerald-50/50' => $w['date'] === $c['date']]) wire:key="wk-{{ $w['date'] }}">
                                <td class="px-4 py-2">
                                    <button type="button" wire:click="$set('date', '{{ $w['date'] }}')" class="text-left font-medium text-emerald-800 hover:underline">{{ $wd->translatedFormat('D, d M') }}</button>
                                </td>
                                @if ($w['closed_day'])
                                    <td colspan="4" class="px-4 py-2 text-right text-stone-400">Tutup</td>
                                @else
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $rp($w['revenue']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $rp($w['cash_sales']) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $w['shifts'] }}</td>
                                    <td class="px-4 py-2 text-right"><x-kas.difference-badge :amount="$w['open_shifts'] && $w['shifts'] === $w['open_shifts'] ? null : $w['difference']" /></td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @include('livewire.kas.partials.shift-form')
</div>
