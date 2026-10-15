@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $r = $this->recap;
    [, , $label] = $this->period;
    $maxMonth = max(1, collect($r['months'])->max(fn ($m) => max($m['disbursed'], $m['collected'])) ?? 1);
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Rekap Pinjaman</h1>
            <p class="text-xs text-stone-500">Pengajuan, pencairan, dan angsuran yang diterima per periode, beserta posisi pinjaman saat ini.</p>
        </div>
        <x-pinjaman.nav active="pinjaman.rekap" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <x-pinjaman.recap-cards :portfolio="$r['portfolio']" />

        <div class="flex flex-wrap items-center gap-2">
            <div class="flex gap-1 rounded-xl bg-stone-100 p-1" role="tablist" aria-label="Jenis periode">
                @foreach (['bulan' => 'Bulanan', 'tahun' => 'Tahunan'] as $k => $t)
                    <button type="button" role="tab" wire:click="setType('{{ $k }}')" aria-selected="{{ $type === $k ? 'true' : 'false' }}"
                            @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-stone-900 shadow-sm' => $type === $k, 'text-stone-600' => $type !== $k])>{{ $t }}</button>
                @endforeach
            </div>
            <div class="flex items-center gap-1">
                <button type="button" wire:click="shift(-1)" class="rounded-lg border border-stone-300 px-2.5 py-1.5 text-sm" aria-label="Periode sebelumnya">‹</button>
                <span class="min-w-36 text-center text-sm font-semibold" data-period>{{ $label }}</span>
                <button type="button" wire:click="shift(1)" @disabled($this->isCurrent) class="rounded-lg border border-stone-300 px-2.5 py-1.5 text-sm disabled:opacity-40" aria-label="Periode berikutnya">›</button>
            </div>
            @if ($type === 'bulan')
                <input type="month" wire:model.live="month" max="{{ today()->format('Y-m') }}" aria-label="Pilih bulan" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm">
            @endif
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
            <section class="rounded-xl border border-stone-200 bg-white" aria-labelledby="by-method">
                <h2 id="by-method" class="border-b border-stone-100 px-4 py-3 text-sm font-semibold">Angsuran diterima per metode</h2>
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach ($r['collected']['by_method'] as $m)
                        <li class="flex items-center justify-between px-4 py-2.5" data-method="{{ $m['method'] }}">
                            <span>{{ $m['label'] }} <span class="text-xs text-stone-500">· {{ $m['count'] }} pembayaran</span></span>
                            <span class="font-semibold tabular-nums">{{ $rp($m['amount']) }}</span>
                        </li>
                    @endforeach
                </ul>
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
