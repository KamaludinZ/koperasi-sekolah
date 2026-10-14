@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $rows = $this->installments;
    $sum = $this->summary;
    $filters = \App\Services\LoanInstallmentQuery::FILTERS;
    $filtered = $filter !== 'bulan_ini' || $month !== '' || $method !== '' || $search !== '';
    $select = 'rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-lg font-semibold">Angsuran Pinjaman</h1>
                <p class="text-xs text-stone-500">Angsuran seluruh pinjaman yang sudah cair: yang jatuh tempo, terlambat, dan yang sudah dibayar.</p>
            </div>
            <a href="{{ route('pinjaman.angsuran.riwayat') }}" wire:navigate class="rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Riwayat pembayaran</a>
        </div>
        <x-pinjaman.nav active="pinjaman.angsuran" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <section class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Ringkasan angsuran">
            <button type="button" wire:click="setFilter('bulan_ini')" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-left hover:border-amber-400" data-card="bulan-ini">
                <p class="text-xs text-amber-900">Jatuh tempo bulan ini (belum dibayar)</p>
                <p class="text-xl font-bold text-amber-950 tabular-nums">{{ $rp($sum['due_month']['amount']) }}</p>
                <p class="text-xs text-amber-900/80">{{ $sum['due_month']['count'] }} angsuran</p>
            </button>
            <button type="button" wire:click="setFilter('terlambat')" @class(['rounded-xl border px-4 py-3 text-left', 'border-red-200 bg-red-50 hover:border-red-400' => $sum['late']['count'] > 0, 'border-stone-200 bg-white hover:border-stone-400' => $sum['late']['count'] === 0]) data-card="terlambat">
                <p @class(['text-xs', 'text-red-800' => $sum['late']['count'] > 0, 'text-stone-500' => $sum['late']['count'] === 0])>Lewat jatuh tempo</p>
                <p @class(['text-xl font-bold tabular-nums', 'text-red-800' => $sum['late']['count'] > 0])>{{ $rp($sum['late']['amount']) }}</p>
                <p class="text-xs text-stone-500">{{ $sum['late']['count'] }} angsuran</p>
            </button>
            <button type="button" wire:click="setFilter('dibayar')" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-left hover:border-emerald-400" data-card="dibayar">
                <p class="text-xs text-emerald-800">Terbayar bulan ini</p>
                <p class="text-xl font-bold text-emerald-900 tabular-nums">{{ $rp($sum['paid_month']['amount']) }}</p>
                <p class="text-xs text-emerald-800/80">{{ $sum['paid_month']['count'] }} angsuran</p>
            </button>
        </section>

        <div class="flex flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center">
            <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-100 p-1" role="tablist" aria-label="Tampilkan angsuran">
                @foreach ($filters as $key => $label)
                    <button type="button" role="tab" wire:click="setFilter('{{ $key }}')" aria-selected="{{ $filter === $key ? 'true' : 'false' }}" data-filter="{{ $key }}"
                            @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-stone-900 shadow-sm' => $filter === $key, 'text-stone-600 hover:text-stone-900' => $filter !== $key])>{{ $label }}</button>
                @endforeach
            </div>
            <input type="month" wire:model.live="month" aria-label="Bulan jatuh tempo" @disabled($filter === 'bulan_ini') class="{{ $select }} disabled:opacity-50">
            <select wire:model.live="method" aria-label="Metode bayar" class="{{ $select }}">
                <option value="">Semua metode</option>
                @foreach (\App\Models\Loan::METHODS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <label class="relative lg:w-64">
                <span class="sr-only">Cari angsuran</span>
                <input type="search" wire:model.live.debounce.250ms="search" maxlength="100" placeholder="Cari kode pinjaman / anggota…" class="{{ $select }} w-full">
            </label>
            @if ($filtered)
                <button type="button" wire:click="resetFilters" class="px-1 text-sm font-medium text-emerald-700 hover:underline">Hapus filter</button>
            @endif
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar angsuran">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Jatuh tempo</th>
                            <th class="px-3 py-2 font-medium">Anggota</th>
                            <th class="px-3 py-2 font-medium">Pinjaman</th>
                            <th class="px-3 py-2 text-right font-medium">Tagihan</th>
                            <th class="px-3 py-2 text-right font-medium">Sisa</th>
                            <th class="px-3 py-2 font-medium">Metode</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($rows as $i)
                            <tr wire:key="inst-{{ $i['id'] }}" @class(['bg-red-50/40' => $i['late'], 'text-stone-500' => $i['status'] === 'dibayar']) data-row="{{ $i['loan']['code'] }}-{{ $i['sequence'] }}">
                                <td class="px-4 py-2.5 whitespace-nowrap">@tanggal($i['due_date'])</td>
                                <td class="px-3 py-2.5">{{ $i['member']['name'] ?? '—' }}<span class="block font-mono text-xs text-stone-500">{{ $i['member']['code'] ?? '' }}</span></td>
                                <td class="px-3 py-2.5"><a href="{{ route('pinjaman.show', $i['loan']['id']) }}" wire:navigate class="font-mono text-xs font-semibold text-emerald-800 hover:underline">{{ $i['loan']['code'] }}</a>
                                    <span class="block text-xs text-stone-500">ke-{{ $i['sequence'] }} dari {{ $i['loan']['tenor_months'] }}</span></td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($i['amount']) }}</td>
                                <td class="px-3 py-2.5 text-right font-medium tabular-nums">{{ $i['remaining'] > 0 ? $rp($i['remaining']) : '—' }}</td>
                                <td class="px-3 py-2.5">{{ $i['loan']['payment_label'] }}</td>
                                <td class="px-4 py-2.5">
                                    @if ($i['status'] === 'dibayar')
                                        <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">Dibayar @tanggal($i['paid_at'])</span>
                                    @elseif ($i['late'])
                                        <span class="rounded-md bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-800">Terlambat {{ $i['days_late'] }} hari</span>
                                    @else
                                        <span class="rounded-md bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-700">{{ $i['paid_amount'] > 0 ? 'Dibayar sebagian' : 'Belum dibayar' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-12 text-center text-stone-400" data-empty="angsuran">
                                {{ $filtered ? 'Tidak ada angsuran yang cocok dengan filter.' : 'Tidak ada angsuran yang jatuh tempo bulan ini.' }}
                                @if ($filtered) <button type="button" wire:click="resetFilters" class="font-medium text-emerald-700 hover:underline">Hapus filter</button>@endif
                            </td></tr>
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
