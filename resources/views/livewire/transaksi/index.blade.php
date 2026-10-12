@php $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.'); @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <h1 class="text-lg font-semibold">Riwayat Transaksi</h1>
        <p class="text-xs text-stone-500">Semua penjualan dari yang terbaru. <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">data tiruan</span> Klik baris untuk melihat rincian.</p>
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        {{-- Ringkasan --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Jumlah transaksi</p>
                <p class="text-2xl font-bold tabular-nums">{{ $this->summary['count'] }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                <p class="text-xs text-emerald-800">Total penjualan</p>
                <p class="text-xl font-bold text-emerald-900 tabular-nums sm:text-2xl">{{ $rp($this->summary['total']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Barang terjual</p>
                <p class="text-2xl font-bold tabular-nums">{{ $this->summary['items'] }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Rata-rata per transaksi</p>
                <p class="text-xl font-bold tabular-nums sm:text-2xl">{{ $rp($this->summary['average']) }}</p>
            </div>
        </div>

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
                <p class="text-sm font-medium text-stone-600">Belum ada transaksi</p>
                <p class="text-xs text-stone-400">Transaksi dari layar kasir akan muncul di sini.</p>
            </div>
        @endforelse
    </div>
</div>
