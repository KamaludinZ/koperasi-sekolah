@php $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.'); @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <h1 class="text-lg font-semibold">Riwayat Transaksi</h1>
        <p class="text-xs text-stone-500">Semua penjualan dari yang terbaru. <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">data tiruan</span> Klik baris untuk melihat rincian.</p>
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        {{-- Filter tanggal --}}
        <section class="rounded-xl border border-stone-200 bg-white p-3 sm:p-4" aria-label="Filter tanggal">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex gap-1.5 overflow-x-auto pb-1 lg:pb-0" role="group" aria-label="Periode cepat">
                    @foreach (\App\Livewire\Transaksi\Index::PRESETS as $key => $label)
                        <button type="button" wire:click="applyPreset('{{ $key }}')" aria-pressed="{{ $preset === $key ? 'true' : 'false' }}"
                                @class(['shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium transition',
                                        'bg-emerald-900 text-white' => $preset === $key,
                                        'border border-stone-300 bg-white text-stone-700 hover:border-stone-400' => $preset !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <label class="text-xs text-stone-600">
                        <span class="mb-1 block">Dari</span>
                        <input type="date" wire:model.live="from" max="{{ now()->toDateString() }}"
                               @class(['rounded-lg border bg-white px-2.5 py-1.5 text-sm outline-none focus:border-emerald-600',
                                       'border-emerald-600 ring-2 ring-emerald-600/15' => $preset === 'kustom',
                                       'border-stone-300' => $preset !== 'kustom'])>
                    </label>
                    <label class="text-xs text-stone-600">
                        <span class="mb-1 block">Sampai</span>
                        <input type="date" wire:model.live="to" max="{{ now()->toDateString() }}"
                               @class(['rounded-lg border bg-white px-2.5 py-1.5 text-sm outline-none focus:border-emerald-600',
                                       'border-emerald-600 ring-2 ring-emerald-600/15' => $preset === 'kustom',
                                       'border-stone-300' => $preset !== 'kustom'])>
                    </label>
                    @if ($preset !== 'semua')
                        <button type="button" wire:click="clearFilter" class="px-2 py-1.5 text-sm font-medium text-stone-500 hover:text-stone-800">Reset</button>
                    @endif
                </div>
            </div>
            @error('to') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
            @if ($this->rangeLabel)
                <p class="mt-2 text-xs text-stone-500">Menampilkan transaksi <strong class="text-stone-700">{{ $this->rangeLabel }}</strong></p>
            @endif
        </section>

        {{-- Ringkasan --}}
        @include('livewire.transaksi.partials.summary')

        @forelse ($this->days as $day)
            <section wire:key="day-{{ $day['date']->toDateString() }}" aria-labelledby="day-{{ $day['date']->toDateString() }}">
                <div class="mb-2 flex items-baseline justify-between gap-3">
                    <h2 id="day-{{ $day['date']->toDateString() }}" class="text-sm font-semibold">
                        @if ($day['date']->isToday()) Hari ini
                        @elseif ($day['date']->isYesterday()) Kemarin
                        @else {{ $day['date']->translatedFormat('l') }}
                        @endif
                        <span class="font-normal text-stone-500">· {{ $day['date']->translatedFormat('d F Y') }}</span>
                    </h2>
                    <p class="text-xs text-stone-500 tabular-nums">{{ $day['count'] }} transaksi · <strong class="text-stone-800">{{ $rp($day['total']) }}</strong></p>
                </div>

                {{-- Tabel (tablet & desktop) --}}
                <div class="hidden overflow-hidden rounded-xl border border-stone-200 bg-white md:block">
                    <table class="w-full text-sm">
                        <thead class="bg-stone-50 text-left text-xs font-medium text-stone-500">
                            <tr>
                                <th class="w-20 px-4 py-2">Jam</th>
                                <th class="px-4 py-2">No. invoice</th>
                                <th class="px-4 py-2">Kasir</th>
                                <th class="px-4 py-2">Anggota</th>
                                <th class="px-4 py-2 text-right">Barang</th>
                                <th class="px-4 py-2">Bayar</th>
                                <th class="px-4 py-2 text-right">Total</th>
                                <th class="w-8"><span class="sr-only">Detail</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($day['rows'] as $t)
                                <x-transaksi.row :tx="$t" wire:key="tx-{{ $t['id'] }}" />
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Kartu (ponsel) --}}
                <ul class="divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 bg-white md:hidden">
                    @foreach ($day['rows'] as $t)
                        <x-transaksi.row :tx="$t" variant="card" wire:key="txm-{{ $t['id'] }}" />
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center">
                @if ($preset === 'semua')
                    <p class="text-sm font-medium text-stone-600">Belum ada transaksi</p>
                    <p class="text-xs text-stone-400">Transaksi dari layar kasir akan muncul di sini.</p>
                @else
                    <p class="text-sm font-medium text-stone-600">Tidak ada transaksi pada periode ini</p>
                    <button type="button" wire:click="clearFilter" class="mt-2 text-sm font-medium text-emerald-700 hover:underline">Tampilkan semua</button>
                @endif
            </div>
        @endforelse
    </div>
</div>
