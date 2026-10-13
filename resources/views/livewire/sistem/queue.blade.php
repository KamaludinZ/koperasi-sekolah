@php
    $w = $this->worker;
    $pending = $this->pending;
    $failed = $this->failed;
    $dt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M, H:i');
    $oldest = collect($pending)->max('waiting_min') ?? 0;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto" wire:poll.30s>
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Sistem</h1>
                <p class="text-xs text-stone-500">Pekerjaan berat (ekspor, notifikasi, cadangan) diproses di latar agar kasir tetap cepat.</p>
            </div>
        </div>
        <x-sistem.nav active="sistem.antrean" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div @class(['rounded-xl border px-4 py-3', 'border-emerald-200 bg-emerald-50' => $w['running'], 'border-red-200 bg-red-50' => ! $w['running']])>
                <p class="text-xs text-stone-600">Pekerja antrean</p>
                <p class="flex items-center gap-2 text-lg font-bold"><span @class(['size-2.5 rounded-full', 'bg-emerald-600' => $w['running'], 'bg-red-600' => ! $w['running']])></span>{{ $w['running'] ? 'Berjalan' : 'Berhenti' }}</p>
                <p class="text-xs text-stone-500">{{ $w['sync'] ? 'mode sinkron (tanpa pekerja)' : ($w['last_seen'] ? 'aktif '.\Illuminate\Support\Carbon::parse($w['last_seen'])->diffForHumans() : 'belum terlihat') }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Menunggu</p>
                <p class="text-2xl font-bold tabular-nums">{{ count($pending) }}</p>
                <p class="text-xs text-stone-500">{{ $pending ? 'terlama '.$oldest.' menit' : 'kosong' }}</p>
            </div>
            <div @class(['rounded-xl border px-4 py-3', 'border-red-200 bg-red-50' => $failed, 'border-stone-200 bg-white' => ! $failed])>
                <p @class(['text-xs', 'text-red-800' => $failed, 'text-stone-500' => ! $failed])>Gagal</p>
                <p @class(['text-2xl font-bold tabular-nums', 'text-red-800' => $failed])>{{ count($failed) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Selesai hari ini</p>
                <p class="text-2xl font-bold tabular-nums">{{ $w['processed_today'] }}</p>
            </div>
        </div>

        @unless ($w['running'])
            <p class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">Pekerja antrean tidak aktif — ekspor, notifikasi, dan cadangan tidak diproses. Jalankan di server: <code class="font-mono">{{ $w['command'] }}</code></p>
        @endunless

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="q-failed">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                <h2 id="q-failed" class="font-semibold">Tugas gagal</h2>
                @if ($failed)<button type="button" wire:click="retryAll" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Ulangi semua</button>@endif
            </div>
            <ul class="mt-2 divide-y divide-stone-100">
                @forelse ($failed as $f)
                    <li wire:key="f-{{ $f['uuid'] }}" class="flex flex-wrap items-start justify-between gap-3 px-4 py-3 text-sm">
                        <span class="min-w-0">
                            <span class="block font-medium">{{ $f['name'] }} <span class="text-xs font-normal text-stone-500">· {{ $f['queue'] }} · {{ $dt($f['failed_at']) }}</span></span>
                            <span class="block font-mono text-xs break-all text-red-700">{{ $f['error'] }}</span>
                        </span>
                        <span class="flex shrink-0 gap-2">
                            <button type="button" wire:click="retry('{{ $f['uuid'] }}')" class="rounded-lg bg-emerald-700 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-800">Ulangi</button>
                            <button type="button" wire:click="forget('{{ $f['uuid'] }}')" class="rounded-lg border border-stone-300 px-3 py-1 text-xs font-medium text-stone-600 hover:bg-stone-50">Hapus</button>
                        </span>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-sm text-stone-400">Tidak ada tugas gagal.</li>
                @endforelse
            </ul>
        </section>

        <div class="grid gap-5 lg:grid-cols-2">
            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="q-pending">
                <h2 id="q-pending" class="px-4 pt-4 font-semibold">Menunggu diproses</h2>
                <ul class="mt-2 divide-y divide-stone-100">
                    @forelse ($pending as $p)
                        <li wire:key="p-{{ $p['id'] }}" class="flex justify-between gap-3 px-4 py-2.5 text-sm">
                            <span>{{ $p['name'] }} <span class="text-xs text-stone-500">· {{ $p['queue'] }}@if ($p['attempts']) · percobaan {{ $p['attempts'] }}@endif</span></span>
                            <span @class(['shrink-0 text-xs tabular-nums', 'font-semibold text-amber-800' => $p['waiting_min'] >= 30, 'text-stone-500' => $p['waiting_min'] < 30])>{{ $p['waiting_min'] }} mnt</span>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-stone-400">Antrean kosong.</li>
                    @endforelse
                </ul>
            </section>
            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="q-done">
                <h2 id="q-done" class="px-4 pt-4 font-semibold">Selesai terbaru</h2>
                <ul class="mt-2 divide-y divide-stone-100">
                    @forelse ($this->recent as $j)
                        <li class="flex justify-between gap-3 px-4 py-2.5 text-sm">
                            <span>{{ $j['name'] }} <span class="text-xs text-stone-500">· {{ $j['queue'] }}@if ($j['by']) · {{ $j['by'] }}@endif</span></span>
                            <span class="shrink-0 text-xs text-stone-500 tabular-nums">{{ $j['duration_s'] !== null ? $j['duration_s'].' dtk · ' : '' }}{{ \Illuminate\Support\Carbon::parse($j['finished_at'])->format('d/m H:i') }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-stone-400">Belum ada pekerjaan selesai yang tercatat.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</div>
