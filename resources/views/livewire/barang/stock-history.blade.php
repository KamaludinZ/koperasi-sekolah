@use('App\Livewire\Barang\StockHistory')
@use('App\Models\StockMovement')
@php $s = $this->summary; $p = $this->product; @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Barang & Stok</h1>
        <p class="text-xs text-stone-500">Setiap perubahan stok tercatat: penjualan, penerimaan barang, retur, dan penyesuaian.</p>
        <x-barang.nav active="barang.riwayat-stok" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
            <div class="relative lg:w-80">
                @if ($p)
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-emerald-600 bg-emerald-50 px-3 py-2 text-sm">
                        <span class="min-w-0 truncate"><span class="font-mono text-xs">{{ $p->code }}</span> {{ $p->name }} <span class="text-xs text-stone-500">· stok {{ $p->stock }}</span></span>
                        <button type="button" wire:click="chooseProduct(null)" class="shrink-0 text-xs font-medium text-emerald-800 hover:underline">Semua barang</button>
                    </div>
                @else
                    <input type="search" wire:model.live.debounce.250ms="productSearch" placeholder="Saring barang (nama/kode)…" aria-label="Saring barang"
                           class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600">
                    @if ($this->productResults)
                        <ul class="absolute inset-x-0 top-full z-10 mt-1 max-h-64 overflow-y-auto rounded-lg border border-stone-200 bg-white shadow-lg">
                            @foreach ($this->productResults as $r)
                                <li><button type="button" wire:click="chooseProduct({{ $r['id'] }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-emerald-50"><span class="font-mono text-xs">{{ $r['code'] }}</span> {{ $r['name'] }}</button></li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>
            <select wire:model.live="type" aria-label="Jenis pergerakan" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">Semua jenis</option>
                @foreach (StockMovement::TYPES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
            <div class="flex gap-1.5 overflow-x-auto" role="group" aria-label="Periode">
                @foreach (StockHistory::PRESETS as $key => $label)
                    <button type="button" wire:click="setPreset('{{ $key }}')" aria-pressed="{{ $preset === $key ? 'true' : 'false' }}"
                            @class(['shrink-0 rounded-full px-3 py-1.5 text-sm font-medium', 'bg-emerald-900 text-white' => $preset === $key, 'border border-stone-300 bg-white text-stone-700' => $preset !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Pergerakan</p><p class="text-2xl font-bold tabular-nums">{{ number_format($s['count'], 0, ',', '.') }}</p></div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Unit masuk</p><p class="text-2xl font-bold text-emerald-800 tabular-nums">+{{ number_format($s['in'], 0, ',', '.') }}</p></div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Unit keluar</p><p class="text-2xl font-bold text-red-700 tabular-nums">−{{ number_format($s['out'], 0, ',', '.') }}</p></div>
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Riwayat pergerakan stok">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr><th class="px-4 py-2 font-medium">Waktu</th><th class="px-3 py-2 font-medium">Barang</th><th class="px-3 py-2 font-medium">Jenis</th><th class="px-3 py-2 text-right font-medium">Perubahan</th><th class="px-3 py-2 text-right font-medium">Stok</th><th class="px-4 py-2 font-medium">Keterangan</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->movements as $m)
                            @php $d = $m->signed_quantity; @endphp
                            <tr wire:key="sm-{{ $m->id }}">
                                <td class="px-4 py-2.5 whitespace-nowrap text-stone-600">{{ $m->created_at->translatedFormat('d M Y, H:i') }}</td>
                                <td class="px-3 py-2.5">
                                    <button type="button" wire:click="chooseProduct({{ $m->product_id }})" class="text-left hover:text-emerald-800 hover:underline"><span class="font-mono text-xs">{{ $m->product?->code }}</span> {{ $m->product?->name ?? '—' }}</button>
                                </td>
                                <td class="px-3 py-2.5"><span @class(['rounded-md px-2 py-0.5 text-[11px] font-semibold', 'bg-emerald-100 text-emerald-800' => $m->type === 'masuk', 'bg-red-100 text-red-800' => $m->type === 'keluar', 'bg-amber-100 text-amber-900' => $m->type === 'penyesuaian'])>{{ $m->type_label }}</span></td>
                                <td @class(['px-3 py-2.5 text-right font-semibold tabular-nums', 'text-emerald-800' => $d > 0, 'text-red-700' => $d < 0])>{{ $d > 0 ? '+' : ($d < 0 ? '−' : '') }}{{ abs($d) }}</td>
                                <td class="px-3 py-2.5 text-right whitespace-nowrap tabular-nums text-stone-600">{{ $m->stock_before ?? '—' }} → <strong class="text-stone-900">{{ $m->stock_after ?? '—' }}</strong></td>
                                <td class="px-4 py-2.5 text-stone-600">{{ $m->note ?? '—' }}<span class="block text-xs text-stone-400">{{ $m->user?->name ?? 'Sistem' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-stone-400">Tidak ada pergerakan stok pada filter ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @if ($this->movements->hasPages())
            <div>{{ $this->movements->links() }}</div>
        @endif
    </div>
</div>
