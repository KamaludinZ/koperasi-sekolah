@php $rp = fn ($n) => \App\Support\Rupiah::format($n); $loans = $this->loans; @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Pinjaman Anggota</h1>
            <p class="text-xs text-stone-500">Seluruh pinjaman anggota: pengajuan, pencairan, angsuran, dan sisa. Dikelola pengurus.</p>
        </div>
        <x-pinjaman.nav active="pinjaman" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        @php $st = $this->stats; $c = $st['counts']; @endphp
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-5" aria-label="Ringkasan status pinjaman">
            <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3" data-loan-card="diajukan">
                <p class="text-xs text-sky-800">Menunggu keputusan</p>
                <p class="text-2xl font-bold text-sky-900 tabular-nums">{{ $c['diajukan'] }}</p>
                <p class="text-xs text-sky-800/80">{{ $c['disetujui'] }} disetujui, belum cair</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3" data-loan-card="berjalan">
                <p class="text-xs text-stone-500">Berjalan</p>
                <p class="text-2xl font-bold tabular-nums">{{ $c['berjalan'] }}</p>
            </div>
            <div @class(['rounded-xl border px-4 py-3', 'border-red-200 bg-red-50' => $c['menunggak'] > 0, 'border-stone-200 bg-white' => $c['menunggak'] === 0]) data-loan-card="menunggak">
                <p @class(['text-xs', 'text-red-800' => $c['menunggak'] > 0, 'text-stone-500' => $c['menunggak'] === 0])>Menunggak</p>
                <p @class(['text-2xl font-bold tabular-nums', 'text-red-800' => $c['menunggak'] > 0])>{{ $c['menunggak'] }}</p>
                @if ($st['overdue_outstanding'] > 0)<p class="text-xs text-red-800/80 tabular-nums">Sisa {{ $rp($st['overdue_outstanding']) }}</p>@endif
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3" data-loan-card="lunas">
                <p class="text-xs text-stone-500">Lunas</p>
                <p class="text-2xl font-bold tabular-nums">{{ $c['lunas'] }}</p>
            </div>
            <div class="col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 lg:col-span-1" data-loan-card="sisa">
                <p class="text-xs text-emerald-800">Total sisa pinjaman aktif</p>
                <p class="text-xl font-bold text-emerald-900 tabular-nums sm:text-2xl">{{ $rp($st['outstanding']) }}</p>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar pinjaman">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Pinjaman</th>
                            <th class="px-3 py-2 font-medium">Anggota</th>
                            <th class="px-3 py-2 text-right font-medium">Pokok</th>
                            <th class="px-3 py-2 text-right font-medium">Bunga</th>
                            <th class="px-3 py-2 font-medium">Tenor</th>
                            <th class="px-3 py-2 font-medium">Metode bayar</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 text-right font-medium">Sisa</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($loans as $l)
                            <tr wire:key="loan-{{ $l['id'] }}" @class(['hover:bg-stone-50', 'bg-red-50/40' => $l['status'] === 'menunggak'])>
                                <td class="px-4 py-2.5"><a href="{{ route('pinjaman.show', $l['id']) }}" wire:navigate class="font-mono text-xs font-semibold text-emerald-800 hover:underline">{{ $l['code'] }}</a>
                                    <span class="block text-xs text-stone-500">Diajukan {{ \Illuminate\Support\Carbon::parse($l['applied_at'])->translatedFormat('d M Y') }}</span></td>
                                <td class="px-3 py-2.5">{{ $l['member']['name'] ?? '—' }}
                                    <span class="block font-mono text-xs text-stone-500">{{ $l['member']['code'] ?? '' }}{{ ! empty($l['member']['class']) ? ' · '.$l['member']['class'] : '' }}</span></td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($l['principal']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($l['interest']) }}</td>
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ $l['tenor_months'] }} bulan<span class="block text-xs text-stone-500">{{ $rp($l['monthly']) }}/bln</span></td>
                                <td class="px-3 py-2.5">{{ $l['payment_label'] }}</td>
                                <td class="px-3 py-2.5"><x-pinjaman.status :status="$l['status']" /></td>
                                <td @class(['px-4 py-2.5 text-right font-semibold tabular-nums', 'text-stone-400' => ! in_array($l['status'], \App\Models\Loan::ACTIVE, true)])>{{ $rp($l['outstanding']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-12 text-center text-stone-400">Belum ada pinjaman anggota.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @if ($loans->hasPages())
            <div class="flex items-center justify-between text-sm">
                <button type="button" wire:click="previousPage" @disabled($loans->onFirstPage()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Sebelumnya</button>
                <span class="text-stone-500">Halaman {{ $loans->currentPage() }} dari {{ $loans->lastPage() }}</span>
                <button type="button" wire:click="nextPage" @disabled(! $loans->hasMorePages()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Berikutnya</button>
            </div>
        @endif
    </div>
</div>
