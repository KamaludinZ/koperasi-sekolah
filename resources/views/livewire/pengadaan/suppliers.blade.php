@use('App\Livewire\Pengadaan\Index')
@use('App\Livewire\Pengadaan\Suppliers')
@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $s = $this->summary;
    $filtered = $search !== '' || $status !== 'aktif' || $category !== '' || $sort !== 'nama';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Pengadaan Barang</h1>
                <p class="text-xs text-stone-500">Pemasok barang koperasi, kontak, dan syarat pembayarannya.</p>
            </div>
            <div class="flex items-center gap-2">
                @if ($url = Index::link('pengadaan.supplier.buat'))
                    <a href="{{ $url }}" wire:navigate class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Tambah supplier</a>
                @else
                    <span title="Segera hadir" class="cursor-not-allowed rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white opacity-60">Tambah supplier</span>
                @endif
            </div>
        </div>
        <x-pengadaan.nav active="pengadaan.supplier" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Supplier aktif</p>
                <p class="text-2xl font-bold tabular-nums">{{ $s['active'] }}</p>
                <p class="text-xs text-stone-500">{{ $s['inactive'] }} nonaktif</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Pembelian 90 hari</p>
                <p class="text-2xl font-bold tabular-nums">{{ $rp($s['purchases_90d']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Hutang berjalan</p>
                <p class="text-2xl font-bold tabular-nums">{{ $rp($s['open_payable']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Boleh tempo</p>
                <p class="text-2xl font-bold tabular-nums">{{ $s['credit'] }}</p>
                <p class="text-xs text-stone-500">supplier aktif</p>
            </div>
        </div>

        {{-- Saringan --}}
        <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
            <label class="relative flex-1">
                <span class="sr-only">Cari supplier</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama, kontak, atau nomor telepon…"
                       class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
            <div class="flex gap-1 rounded-xl bg-stone-200/60 p-1" role="group" aria-label="Status">
                @foreach (['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif', 'semua' => 'Semua'] as $key => $label)
                    <button type="button" wire:click="setStatus('{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}"
                            @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $status === $key, 'text-stone-600 hover:text-stone-900' => $status !== $key])>{{ $label }}</button>
                @endforeach
            </div>
            <select wire:model.live="category" aria-label="Kategori barang" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">Semua kategori</option>
                @foreach ($this->categories as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
            </select>
            <select wire:model.live="sort" aria-label="Urutkan" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                @foreach (Suppliers::SORTS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            @if ($filtered)
                <button type="button" wire:click="resetFilters" class="px-2 text-sm font-medium text-stone-500 hover:text-stone-800">Reset</button>
            @endif
        </div>

        {{-- Daftar --}}
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar supplier">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Supplier</th>
                            <th class="px-3 py-2 font-medium">Kontak</th>
                            <th class="px-3 py-2 font-medium">Barang</th>
                            <th class="px-3 py-2 font-medium">Pembayaran</th>
                            <th class="px-3 py-2 text-right font-medium">Pembelian 90 hari</th>
                            <th class="px-3 py-2 text-right font-medium">Hutang</th>
                            <th class="px-4 py-2 font-medium">Pesanan terakhir</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->suppliers as $sup)
                            <tr wire:key="sup-{{ $sup['id'] }}" @class(['hover:bg-stone-50', 'text-stone-500' => ! $sup['is_active']])>
                                <td class="px-4 py-2.5">
                                    <button type="button" wire:click="show({{ $sup['id'] }})" class="text-left font-medium text-emerald-800 hover:underline">{{ $sup['name'] }}</button>
                                    @unless ($sup['is_active'])<span class="ml-1 rounded bg-stone-200 px-1.5 text-[11px] text-stone-600">nonaktif</span>@endunless
                                    <span class="block max-w-64 truncate text-xs text-stone-500" title="{{ $sup['address'] }}">{{ $sup['address'] }}</span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="block">{{ $sup['contact'] }}</span>
                                    <span class="text-xs text-stone-500 tabular-nums">{{ $sup['phone'] }}</span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="flex flex-wrap gap-1">
                                        @foreach ($sup['categories'] as $c)<span class="rounded bg-stone-100 px-1.5 py-0.5 text-[11px] text-stone-700">{{ $c }}</span>@endforeach
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ $sup['terms_days'] ? 'Tempo '.$sup['terms_days'].' hari' : 'Tunai' }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($sup['purchases_90d']) }}</td>
                                <td @class(['px-3 py-2.5 text-right tabular-nums', 'font-semibold text-amber-800' => $sup['open_payable'] > 0, 'text-stone-400' => $sup['open_payable'] === 0])>{{ $sup['open_payable'] ? $rp($sup['open_payable']) : '—' }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-stone-600">{{ $sup['last_order_at'] ? \Illuminate\Support\Carbon::parse($sup['last_order_at'])->diffForHumans() : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-stone-400">Tidak ada supplier yang cocok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- Rincian --}}
    @if ($sel = $this->selected)
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="sup-detail-title"
             x-data x-on:keydown.escape.window="$wire.closeDetail()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="closeDetail"></div>
            <div class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div>
                        <p class="text-xs text-stone-500">{{ implode(', ', $sel['categories']) }}</p>
                        <h2 id="sup-detail-title" class="font-semibold">{{ $sel['name'] }}</h2>
                    </div>
                    <button type="button" wire:click="closeDetail" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 text-sm">
                    <dl class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2">
                        <dt class="text-stone-500">Kontak</dt><dd>{{ $sel['contact'] }}</dd>
                        <dt class="text-stone-500">Telepon</dt><dd class="tabular-nums">{{ $sel['phone'] }}</dd>
                        <dt class="text-stone-500">Alamat</dt><dd>{{ $sel['address'] }}</dd>
                        <dt class="text-stone-500">Pembayaran</dt><dd>{{ $sel['terms_days'] ? 'Tempo '.$sel['terms_days'].' hari' : 'Tunai' }}</dd>
                        <dt class="text-stone-500">Catatan</dt><dd>{{ $sel['note'] ?? '—' }}</dd>
                    </dl>
                    <div class="flex gap-2">
                        <a href="{{ route('pengadaan.supplier.ubah', $sel['id']) }}" wire:navigate class="flex-1 rounded-xl bg-emerald-700 px-3 py-2 text-center font-semibold text-white hover:bg-emerald-800">Ubah</a>
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $sel['phone']) }}" class="flex-1 rounded-xl border border-stone-300 px-3 py-2 text-center font-medium text-stone-700 hover:bg-stone-50">Telepon</a>
                        @if ($wa = Suppliers::whatsapp($sel['phone']))
                            <a href="{{ $wa }}" target="_blank" rel="noopener" class="flex-1 rounded-xl border border-emerald-700 px-3 py-2 text-center font-semibold text-emerald-800 hover:bg-emerald-50">WhatsApp</a>
                        @endif
                    </div>

                    <section>
                        <h3 class="mb-2 font-semibold">Hutang berjalan</h3>
                        @forelse ($sel['payables'] as $p)
                            <p class="flex justify-between gap-2 border-b border-stone-100 py-1.5 text-xs"><span class="font-mono">{{ $p['po_number'] }}</span>
                                <span>{{ $rp($p['amount'] - $p['paid']) }} · jatuh tempo {{ \Illuminate\Support\Carbon::parse($p['due_at'])->translatedFormat('d M') }}</span></p>
                        @empty
                            <p class="text-xs text-stone-500">Tidak ada hutang.</p>
                        @endforelse
                    </section>

                    <section>
                        <h3 class="mb-2 font-semibold">Pesanan</h3>
                        @forelse ($sel['orders'] as $o)
                            @php [$label, $tone] = Index::ORDER_STATUSES[$o['status']]; @endphp
                            <p class="flex items-center justify-between gap-2 border-b border-stone-100 py-1.5 text-xs">
                                <span><span class="font-mono">{{ $o['number'] }}</span> · {{ \Illuminate\Support\Carbon::parse($o['ordered_at'])->translatedFormat('d M') }}</span>
                                <span class="flex items-center gap-2"><span class="rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $tone }}">{{ $label }}</span><span class="tabular-nums">{{ $rp($o['total']) }}</span></span>
                            </p>
                        @empty
                            <p class="text-xs text-stone-500">Belum ada pesanan.</p>
                        @endforelse
                    </section>

                    @can('kelola-pengadaan')
                        <section class="rounded-lg border border-red-200 p-3">
                            @if ($confirmingDelete)
                                <label class="block text-xs"><span class="font-medium text-stone-700">Alasan menghapus (opsional)</span>
                                    <input type="text" wire:model="deleteReason" maxlength="200" class="mt-1 w-full rounded-lg border border-stone-300 px-2.5 py-1.5 text-sm"></label>
                                @error('deleteReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                <div class="mt-2 flex justify-end gap-2">
                                    <button type="button" wire:click="$set('confirmingDelete', false)" class="rounded-lg px-3 py-1.5 text-xs font-medium text-stone-600 hover:bg-stone-100">Batal</button>
                                    <button type="button" wire:click="delete" class="rounded-lg bg-red-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-800">Hapus supplier</button>
                                </div>
                            @else
                                <button type="button" wire:click="$set('confirmingDelete', true)" class="text-xs font-semibold text-red-700 hover:underline">Hapus supplier…</button>
                                <p class="text-xs text-stone-500">Supplier yang masih punya pesanan berjalan atau hutang tidak bisa dihapus; nonaktifkan saja.</p>
                            @endif
                        </section>
                    @endcan
                </div>
            </div>
        </div>
    @endif
</div>
