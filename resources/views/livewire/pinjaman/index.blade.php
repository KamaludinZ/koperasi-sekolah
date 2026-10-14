@php $rp = fn ($n) => \App\Support\Rupiah::format($n); $loans = $this->loans; $filtered = $search !== '' || $status !== '' || $method !== ''; $none = ! $filtered && $loans->total() === 0; @endphp
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
            <div @class(['cursor-pointer rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 hover:border-sky-400', 'ring-2 ring-sky-500' => $status === 'diajukan']) data-loan-card="diajukan" role="button" tabindex="0" wire:click="filterStatus('diajukan')" wire:keydown.enter="filterStatus('diajukan')" aria-pressed="{{ $status === 'diajukan' ? 'true' : 'false' }}">
                <p class="text-xs text-sky-800">Menunggu keputusan</p>
                <p class="text-2xl font-bold text-sky-900 tabular-nums">{{ $c['diajukan'] }}</p>
                <p class="text-xs text-sky-800/80">{{ $c['disetujui'] }} disetujui, belum cair</p>
            </div>
            <div @class(['cursor-pointer rounded-xl border border-stone-200 bg-white px-4 py-3 hover:border-stone-400', 'ring-2 ring-emerald-600' => $status === 'berjalan']) data-loan-card="berjalan" role="button" tabindex="0" wire:click="filterStatus('berjalan')" wire:keydown.enter="filterStatus('berjalan')" aria-pressed="{{ $status === 'berjalan' ? 'true' : 'false' }}">
                <p class="text-xs text-stone-500">Berjalan</p>
                <p class="text-2xl font-bold tabular-nums">{{ $c['berjalan'] }}</p>
            </div>
            <div @class(['cursor-pointer rounded-xl border px-4 py-3 hover:border-red-400', 'ring-2 ring-red-500' => $status === 'menunggak', 'border-red-200 bg-red-50' => $c['menunggak'] > 0, 'border-stone-200 bg-white' => $c['menunggak'] === 0]) data-loan-card="menunggak" role="button" tabindex="0" wire:click="filterStatus('menunggak')" wire:keydown.enter="filterStatus('menunggak')" aria-pressed="{{ $status === 'menunggak' ? 'true' : 'false' }}">
                <p @class(['text-xs', 'text-red-800' => $c['menunggak'] > 0, 'text-stone-500' => $c['menunggak'] === 0])>Menunggak</p>
                <p @class(['text-2xl font-bold tabular-nums', 'text-red-800' => $c['menunggak'] > 0])>{{ $c['menunggak'] }}</p>
                @if ($st['overdue_outstanding'] > 0)<p class="text-xs text-red-800/80 tabular-nums">Sisa {{ $rp($st['overdue_outstanding']) }}</p>@endif
            </div>
            <div @class(['cursor-pointer rounded-xl border border-stone-200 bg-white px-4 py-3 hover:border-stone-400', 'ring-2 ring-stone-500' => $status === 'lunas']) data-loan-card="lunas" role="button" tabindex="0" wire:click="filterStatus('lunas')" wire:keydown.enter="filterStatus('lunas')" aria-pressed="{{ $status === 'lunas' ? 'true' : 'false' }}">
                <p class="text-xs text-stone-500">Lunas</p>
                <p class="text-2xl font-bold tabular-nums">{{ $c['lunas'] }}</p>
            </div>
            <div class="col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 lg:col-span-1" data-loan-card="sisa">
                <p class="text-xs text-emerald-800">Total sisa pinjaman aktif</p>
                <p class="text-xl font-bold text-emerald-900 tabular-nums sm:text-2xl">{{ $rp($st['outstanding']) }}</p>
            </div>
        </section>

        @if ($none)
            <div class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center" data-empty="pinjaman">
                <svg class="mx-auto size-10 text-stone-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h3"/></svg>
                <p class="mt-3 text-sm font-medium text-stone-700">Belum ada pinjaman anggota</p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-stone-500">Pengajuan pinjaman dari anggota akan tampil di sini beserta status, angsuran, dan sisanya.</p>
                @if (\Illuminate\Support\Facades\Route::has('pinjaman.pengajuan'))
                    <a href="{{ route('pinjaman.pengajuan') }}" wire:navigate class="mt-4 inline-block rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Catat pengajuan</a>
                @endif
            </div>
        @else
        @php $select = 'rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15'; @endphp
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <label class="relative flex-1 sm:min-w-56">
                <span class="sr-only">Cari pinjaman</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="search" maxlength="100" placeholder="Cari kode pinjaman, nama, atau kode anggota…"
                       class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
            <select wire:model.live="status" aria-label="Status pinjaman" class="{{ $select }}">
                <option value="">Semua status</option>
                @foreach (\App\Models\Loan::STATUSES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="method" aria-label="Metode bayar" class="{{ $select }}">
                <option value="">Semua metode</option>
                @foreach (\App\Models\Loan::METHODS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @if ($filtered)
                <button type="button" wire:click="resetFilters" class="px-1 text-sm font-medium text-emerald-700 hover:underline">Hapus filter</button>
            @endif
        </div>

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
                                    <span class="block text-xs text-stone-500">Diajukan @tanggal($l['applied_at'])</span></td>
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
                            <tr><td colspan="8" class="px-4 py-12 text-center text-stone-400">
                                Tidak ada pinjaman yang cocok. <button type="button" wire:click="resetFilters" class="font-medium text-emerald-700 hover:underline">Hapus filter</button>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @endif
        @if ($loans->hasPages())
            <div class="flex items-center justify-between text-sm">
                <button type="button" wire:click="previousPage" @disabled($loans->onFirstPage()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Sebelumnya</button>
                <span class="text-stone-500">Halaman {{ $loans->currentPage() }} dari {{ $loans->lastPage() }}</span>
                <button type="button" wire:click="nextPage" @disabled(! $loans->hasMorePages()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Berikutnya</button>
            </div>
        @endif
    </div>
</div>
