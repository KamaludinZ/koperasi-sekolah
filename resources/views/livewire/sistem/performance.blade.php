@use('App\Livewire\Sistem\Performance')
@use('App\Support\SystemHealth')
@php $missing = $this->missing; @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Sistem</h1>
        <p class="text-xs text-stone-500">Cache mempercepat laporan; indeks mempercepat pencarian di data yang besar.</p>
        <x-sistem.nav active="sistem.performa" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white" aria-labelledby="pf-cache">
            <div class="flex flex-wrap items-center justify-between gap-2 px-5 pt-5">
                <div>
                    <h2 id="pf-cache" class="font-semibold">Cache laporan <span class="text-xs font-normal text-stone-400">data contoh</span></h2>
                    <p class="text-xs text-stone-500">Store: {{ config('cache.default') }}. Cache diperbarui otomatis saat ada penjualan baru atau masa berlakunya habis.</p>
                </div>
                <button type="button" wire:click="clearAll" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Kosongkan semua</button>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500"><tr><th class="px-5 py-2 font-medium">Data</th><th class="px-3 py-2 font-medium">Umur</th><th class="px-3 py-2 font-medium">Rasio kena</th><th class="px-3 py-2 text-right font-medium">Ukuran</th><th class="px-5 py-2"></th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($this->caches as $c)
                            @php $rate = Performance::hitRate($c); @endphp
                            <tr wire:key="c-{{ $c['key'] }}">
                                <td class="px-5 py-2.5"><span class="font-medium">{{ $c['label'] }}</span><span class="block font-mono text-[11px] text-stone-500">{{ $c['key'] }}</span></td>
                                <td class="px-3 py-2.5 text-stone-600">{{ $c['age_min'] === null ? 'kosong' : ($c['age_min'] < 1 ? 'baru saja' : $c['age_min'].' mnt') }}<span class="block text-[11px] text-stone-400">{{ $c['ttl_min'] ? 'berlaku '.$c['ttl_min'].' mnt' : 'sampai diubah' }}</span></td>
                                <td class="px-3 py-2.5">
                                    <span class="flex items-center gap-2"><span class="h-1.5 w-20 overflow-hidden rounded-full bg-stone-100" aria-hidden="true"><span @class(['block h-full rounded-full', 'bg-emerald-600' => $rate >= 70, 'bg-amber-500' => $rate < 70]) style="width: {{ $rate }}%"></span></span><span class="text-xs tabular-nums">{{ $rate }}%</span></span>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-stone-600">{{ SystemHealth::bytes($c['size']) }}</td>
                                <td class="px-5 py-2.5 text-right"><button type="button" wire:click="clear('{{ $c['key'] }}')" @disabled($c['age_min'] === null) class="text-xs font-medium text-emerald-800 hover:underline disabled:text-stone-300 disabled:no-underline">Kosongkan</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="pf-idx">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="pf-idx" class="font-semibold">Indeks basis data</h2>
                <span @class(['rounded-full px-2.5 py-0.5 text-xs font-semibold', 'bg-emerald-100 text-emerald-800' => ! $missing, 'bg-amber-100 text-amber-900' => $missing])>{{ $missing ? $missing.' indeks belum ada' : 'Semua indeks penting tersedia' }}</span>
            </div>
            <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->indexes as $t)
                    <article wire:key="ix-{{ $t['table'] }}" class="rounded-xl border border-stone-200 p-3" data-table="{{ $t['table'] }}">
                        <p class="flex justify-between gap-2 text-sm"><span class="font-mono font-semibold">{{ $t['table'] }}</span><span class="text-xs text-stone-500 tabular-nums">{{ number_format($t['rows'], 0, ',', '.') }} baris</span></p>
                        <ul class="mt-2 space-y-1 text-xs">
                            @foreach ($t['checks'] as $ch)
                                <li class="flex items-start gap-2">
                                    <span @class(['mt-0.5 grid size-4 shrink-0 place-items-center rounded-full text-[10px] font-bold', 'bg-emerald-100 text-emerald-800' => $ch['ok'], 'bg-amber-100 text-amber-900' => ! $ch['ok']])>{{ $ch['ok'] ? '✓' : '!' }}</span>
                                    <span><span class="font-mono">{{ $ch['column'] }}</span> — {{ $ch['purpose'] }}@unless ($ch['ok'])<span class="block font-semibold text-amber-800">belum diindeks</span>@endunless</span>
                                </li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="pf-slow">
            <h2 id="pf-slow" class="font-semibold">Halaman lambat minggu ini <span class="text-xs font-normal text-stone-400">data contoh</span></h2>
            <ul class="mt-2 divide-y divide-stone-100 text-sm">
                @forelse (\App\Support\MockData::slowQueries() as $q)
                    <li class="flex flex-wrap justify-between gap-2 py-2.5"><span>{{ $q['page'] }} <span class="block text-xs text-stone-500">{{ $q['hint'] }}</span></span><span class="text-xs text-stone-600 tabular-nums">{{ $q['ms'] }} ms · {{ $q['count'] }}×</span></li>
                @empty
                    <li class="py-4 text-center text-stone-400">Tidak ada halaman lambat.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
