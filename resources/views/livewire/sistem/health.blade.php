@use('App\Livewire\Sistem\Health')
@use('App\Support\SystemHealth')
@php
    $r = $this->report;
    [$banner, $dot] = Health::TONES[$r['status']];
    $m = $r['metrics'];
    $dt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y, H:i');
    $icons = [
        'database' => 'M4 7c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zm0 0v10c0 1.7 3.6 3 8 3s8-1.3 8-3V7M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3',
        'cache' => 'M13 10V3L4 14h7v7l9-11h-7z',
        'storage' => 'M4 4h16v6H4zM4 14h16v6H4zM8 7h.01M8 17h.01',
        'queue' => 'M4 6h16M4 12h10M4 18h6',
        'scheduler' => 'M12 8v4l3 3M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'backup' => 'M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3',
        'app' => 'M9 17v-2a3 3 0 013-3h0a3 3 0 013 3v2M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z',
    ];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto" wire:poll.60s>
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Sistem</h1>
                <p class="text-xs text-stone-500">Kondisi server, basis data, antrean, dan cadangan data koperasi.</p>
            </div>
            <button type="button" wire:click="recheck" wire:loading.attr="disabled" class="inline-flex items-center gap-2 rounded-xl border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">
                <svg class="size-4" wire:loading.class="animate-spin" wire:target="recheck" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4v5h5M20 20v-5h-5M5.6 15a7 7 0 0011.8 2.5M18.4 9A7 7 0 006.6 6.5"/></svg>
                Periksa ulang
            </button>
        </div>
        <x-sistem.nav active="sistem" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        <section class="flex flex-wrap items-center gap-4 rounded-2xl border px-5 py-4 {{ $banner }}" role="status" aria-live="polite">
            <span class="relative flex size-4"><span class="absolute inline-flex size-full animate-ping rounded-full opacity-40 {{ $dot }}"></span><span class="relative inline-flex size-4 rounded-full {{ $dot }}"></span></span>
            <div class="min-w-0 flex-1">
                <p class="text-lg font-semibold">{{ $r['status'] === 'ok' ? 'Semua sistem berjalan normal' : ($r['status'] === 'peringatan' ? 'Ada yang perlu diperhatikan' : 'Ada gangguan pada sistem') }}</p>
                <p class="text-xs opacity-80">Diperiksa {{ $dt($r['checked_at']) }} · diperbarui otomatis tiap menit</p>
            </div>
            <p class="text-sm font-medium tabular-nums">{{ collect($r['checks'])->where('status', 'ok')->count() }}/{{ count($r['checks']) }} normal</p>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Pemeriksaan">
            @foreach ($r['checks'] as $c)
                @php [$tone, $cdot] = Health::TONES[$c['status']]; @endphp
                <article wire:key="chk-{{ $c['key'] }}" class="rounded-xl border border-stone-200 bg-white p-4" data-check="{{ $c['key'] }}">
                    <div class="flex items-start justify-between gap-2">
                        <span class="grid size-9 place-items-center rounded-lg bg-stone-100 text-stone-600">
                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icons[$c['key']] ?? $icons['app'] }}"/></svg>
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $tone }}"><span class="size-1.5 rounded-full {{ $cdot }}"></span>{{ SystemHealth::STATUSES[$c['status']] }}</span>
                    </div>
                    <h2 class="mt-3 font-semibold">{{ $c['label'] }}</h2>
                    <p class="text-sm text-stone-600">{{ $c['detail'] }}</p>
                    @if ($c['key'] === 'storage' && isset($c['meta']['free_pct']))
                        <span class="mt-2 block h-1.5 overflow-hidden rounded-full bg-stone-100" aria-hidden="true"><span class="block h-full rounded-full {{ $cdot }}" style="width: {{ 100 - $c['meta']['free_pct'] }}%"></span></span>
                    @endif
                    @if ($c['meta']['mock'] ?? false)<p class="mt-1 text-[11px] text-stone-400">data contoh</p>@endif
                </article>
            @endforeach
        </section>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="inc-title">
                <h2 id="inc-title" class="font-semibold">Gangguan terbaru</h2>
                @if ($r['incidents'])
                    <ol class="mt-3 space-y-3 border-l-2 border-stone-100 pl-4">
                        @foreach ($r['incidents'] as $i)
                            <li class="relative text-sm">
                                <span @class(['absolute top-1.5 -left-[1.4rem] size-2.5 rounded-full ring-4 ring-white', 'bg-amber-500' => $i['status'] === 'peringatan', 'bg-red-600' => $i['status'] === 'gangguan'])></span>
                                <p class="font-medium">{{ $i['message'] }}</p>
                                <p class="text-xs text-stone-500">{{ $dt($i['at']) }}@if ($i['resolved_at']) · pulih {{ \Illuminate\Support\Carbon::parse($i['resolved_at'])->format('H:i') }}@else · <span class="font-semibold text-red-700">belum pulih</span>@endif</p>
                            </li>
                        @endforeach
                    </ol>
                    <p class="mt-3 text-[11px] text-stone-400">data contoh</p>
                @else
                    <p class="mt-2 text-sm text-stone-500">Tidak ada gangguan dalam 30 hari terakhir.</p>
                @endif
            </section>

            <aside class="h-fit rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="met-title">
                <h2 id="met-title" class="font-semibold">Angka ringkas</h2>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    @foreach ([['Transaksi hari ini', $m['transactions_today']], ['Petugas aktif', $m['sessions_active']], ['Total transaksi', $m['transactions']], ['Barang', $m['products']], ['Anggota', $m['members']], ['Catatan audit', $m['activity_logs']]] as [$label, $value])
                        <div class="rounded-lg bg-stone-50 px-3 py-2"><dt class="text-xs text-stone-500">{{ $label }}</dt><dd class="text-lg font-semibold tabular-nums">{{ number_format($value, 0, ',', '.') }}</dd></div>
                    @endforeach
                </dl>
            </aside>
        </div>
    </div>
</div>
