@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $rows = $this->rows;
    $sum = $this->summary;
    $filtered = $bucket !== '' || $method !== '' || $search !== '';
    $select = 'rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
    $tone = ['1-30' => 'bg-amber-100 text-amber-900', '31-60' => 'bg-orange-100 text-orange-900', '61-90' => 'bg-red-100 text-red-800', '90+' => 'bg-red-700 text-white'];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Tunggakan Pinjaman</h1>
            <p class="text-xs text-stone-500">Pinjaman yang punya angsuran lewat jatuh tempo{{ \App\Support\LoanOverdue::graceDays() > 0 ? ' (setelah toleransi '.\App\Support\LoanOverdue::graceDays().' hari)' : '' }}, dikelompokkan menurut lamanya.</p>
        </div>
        <x-pinjaman.nav active="pinjaman.tunggakan" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-5" aria-label="Ringkasan tunggakan">
            <div @class(['col-span-2 rounded-xl border px-4 py-3 lg:col-span-1', 'border-red-200 bg-red-50' => $sum['loans'] > 0, 'border-stone-200 bg-white' => $sum['loans'] === 0]) data-card="total">
                <p class="text-xs text-stone-600">Total tunggakan</p>
                <p @class(['text-xl font-bold tabular-nums', 'text-red-800' => $sum['loans'] > 0]) data-total-arrears>{{ $rp($sum['amount']) }}</p>
                <p class="text-xs text-stone-500">{{ $sum['loans'] }} pinjaman · {{ $sum['installments'] }} angsuran</p>
            </div>
            @foreach ($sum['buckets'] as $key => $b)
                <button type="button" wire:click="setBucket('{{ $key }}')" aria-pressed="{{ $bucket === $key ? 'true' : 'false' }}" data-bucket="{{ $key }}"
                        @class(['rounded-xl border px-4 py-3 text-left hover:border-stone-400', 'border-stone-900 ring-2 ring-stone-900/10 bg-white' => $bucket === $key, 'border-stone-200 bg-white' => $bucket !== $key])>
                    <p class="text-xs text-stone-500">{{ $b['label'] }}</p>
                    <p @class(['text-lg font-bold tabular-nums', 'text-stone-400' => $b['count'] === 0])>{{ $rp($b['amount']) }}</p>
                    <p class="text-xs text-stone-500">{{ $b['count'] }} pinjaman</p>
                </button>
            @endforeach
        </section>

        <div class="flex flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center">
            <select wire:model.live="method" aria-label="Metode bayar" class="{{ $select }}">
                <option value="">Semua metode</option>
                @foreach (\App\Models\Loan::METHODS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="sort" aria-label="Urutkan" class="{{ $select }}">
                @foreach (\App\Services\LoanArrearsQuery::SORTS as $value => $label)
                    <option value="{{ $value }}">Urut: {{ $label }}</option>
                @endforeach
            </select>
            <label class="relative lg:w-64">
                <span class="sr-only">Cari tunggakan</span>
                <input type="search" wire:model.live.debounce.250ms="search" maxlength="100" placeholder="Cari kode pinjaman / anggota…" class="{{ $select }} w-full">
            </label>
            @if ($filtered)
                <button type="button" wire:click="resetFilters" class="px-1 text-sm font-medium text-emerald-700 hover:underline">Hapus filter</button>
            @endif
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar pinjaman menunggak">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Anggota</th>
                            <th class="px-3 py-2 font-medium">Pinjaman</th>
                            <th class="px-3 py-2 font-medium">Tertua</th>
                            <th class="px-3 py-2 font-medium">Lama</th>
                            <th class="px-3 py-2 text-right font-medium">Tertunggak</th>
                            <th class="px-3 py-2 text-right font-medium">Sisa pinjaman</th>
                            <th class="px-4 py-2 font-medium">Metode</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($rows as $r)
                            <tr wire:key="arrear-{{ $r['id'] }}" data-arrear="{{ $r['code'] }}">
                                <td class="px-4 py-2.5">{{ $r['member']['name'] ?? '—' }}
                                    <span class="block text-xs text-stone-500"><span class="font-mono">{{ $r['member']['code'] ?? '' }}</span>{{ ($r['member']['phone'] ?? null) ? ' · '.$r['member']['phone'] : '' }}</span></td>
                                <td class="px-3 py-2.5"><a href="{{ route('pinjaman.show', $r['id']) }}" wire:navigate class="font-mono text-xs font-semibold text-emerald-800 hover:underline">{{ $r['code'] }}</a>
                                    <span class="block text-xs text-stone-500">{{ $r['late_count'] }} angsuran tertunggak</span></td>
                                <td class="px-3 py-2.5 whitespace-nowrap">@tanggal($r['oldest_due'])</td>
                                <td class="px-3 py-2.5"> <span class="rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $tone[$r['bucket']] }}" data-days="{{ $r['days_late'] }}">{{ $r['days_late'] }} hari</span></td>
                                <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-red-800">{{ $rp($r['late_amount']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($r['outstanding']) }}</td>
                                <td class="px-4 py-2.5">{{ $r['payment_label'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-12 text-center text-stone-400" data-empty="tunggakan">
                                @if ($filtered)
                                    Tidak ada tunggakan yang cocok dengan filter. <button type="button" wire:click="resetFilters" class="font-medium text-emerald-700 hover:underline">Hapus filter</button>
                                @else
                                    <span class="font-medium text-emerald-700">Tidak ada tunggakan.</span> Semua angsuran yang jatuh tempo sudah dibayar.
                                @endif
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
