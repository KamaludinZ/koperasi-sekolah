@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $rows = $this->payments;
    $t = $this->totals;
    $select = 'rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-lg font-semibold">Riwayat Pembayaran Angsuran</h1>
                <p class="text-xs text-stone-500">Setiap uang angsuran yang diterima, berurutan per tanggal bayar. Pembayaran yang dikoreksi tampil dicoret.</p>
            </div>
            <a href="{{ route('pinjaman.angsuran') }}" wire:navigate class="text-sm font-medium text-emerald-800 hover:underline">&larr; Daftar angsuran</a>
        </div>
        <x-pinjaman.nav active="pinjaman.angsuran" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <input type="month" wire:model.live="month" aria-label="Bulan bayar" max="{{ today()->format('Y-m') }}" class="{{ $select }}">
            <select wire:model.live="method" aria-label="Metode bayar" class="{{ $select }}">
                <option value="">Semua metode</option>
                @foreach (\App\Models\Loan::METHODS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <input type="search" wire:model.live.debounce.250ms="search" maxlength="100" placeholder="Cari kode pinjaman / anggota…" aria-label="Cari pembayaran" class="{{ $select }} sm:w-64">
        </div>

        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Total bulan ini" data-totals>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                <p class="text-xs text-emerald-800">Total diterima</p>
                <p class="text-xl font-bold text-emerald-900 tabular-nums">{{ $rp($t['amount']) }}</p>
                <p class="text-xs text-emerald-800/80">{{ $t['count'] }} pembayaran</p>
            </div>
            @foreach (\App\Models\Loan::METHODS as $m => $label)
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">{{ $label }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $rp($t['by_method'][$m]) }}</p>
                </div>
            @endforeach
        </section>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Riwayat pembayaran">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Tanggal bayar</th>
                            <th class="px-3 py-2 font-medium">Pinjaman</th>
                            <th class="px-3 py-2 text-right font-medium">Nominal</th>
                            <th class="px-3 py-2 font-medium">Metode</th>
                            <th class="px-3 py-2 font-medium">Angsuran</th>
                            <th class="px-4 py-2 font-medium">Dicatat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($rows as $p)
                            <tr wire:key="hist-{{ $p['id'] }}" @class(['text-stone-400 line-through' => $p['voided']]) data-history="{{ $p['id'] }}">
                                <td class="px-4 py-2.5 whitespace-nowrap">@tanggal($p['paid_at'])</td>
                                <td class="px-3 py-2.5"><a href="{{ route('pinjaman.show', $p['loan']['id']) }}" wire:navigate class="font-mono text-xs font-semibold text-emerald-800 hover:underline">{{ $p['loan']['code'] }}</a>
                                    <span class="block text-xs text-stone-500">{{ $p['member'] ?? '—' }}</span></td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($p['amount']) }}</td>
                                <td class="px-3 py-2.5">{{ $p['payment_label'] }}@if ($p['reference_label'])<span class="block text-xs text-stone-500">{{ $p['reference_label'] }}</span>@endif</td>
                                <td class="px-3 py-2.5">ke-{{ implode(', ', $p['sequences']) }}@if ($p['voided'])<span class="block text-xs text-red-700">Dikoreksi: {{ $p['void_reason'] }}</span>@endif</td>
                                <td class="px-4 py-2.5 text-xs text-stone-500">{{ $p['user'] ?? '—' }}<span class="block">{{ \App\Support\Tanggal::format($p['recorded_at'], true) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-12 text-center text-stone-400" data-empty="riwayat">Belum ada pembayaran angsuran di bulan ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @if ($rows->hasPages())
            <div class="flex items-center justify-between text-sm">
                <button type="button" wire:click="previousPage" @disabled($rows->onFirstPage()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Sebelumnya</button>
                <span class="text-stone-500">Halaman {{ $rows->currentPage() }} dari {{ $rows->lastPage() }}</span>
                <button type="button" wire:click="nextPage" @disabled(! $rows->hasMorePages()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Berikutnya</button>
            </div>
        @endif
    </div>
</div>
