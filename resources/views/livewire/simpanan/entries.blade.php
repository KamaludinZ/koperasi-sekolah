@use('App\Livewire\Simpanan\Entries')
@use('App\Models\MemberSavingEntry')
@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $t = $this->totals;
    $select = 'rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Simpanan Anggota</h1>
            <p class="text-xs text-stone-500">Riwayat setoran, penarikan, dan potongan belanja per jenis simpanan.</p>
        </div>
        <x-simpanan.nav active="simpanan.mutasi" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        @if ($m = $this->member)
            <p class="flex flex-wrap items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                Anggota: <a href="{{ route('anggota.show', $m['id']) }}" wire:navigate class="font-semibold hover:underline">{{ $m['code'] }} · {{ $m['name'] }}</a>
                <button type="button" wire:click="clearMember" class="ml-auto text-xs font-medium hover:underline">Tampilkan semua anggota</button>
            </p>
        @endif

        <div class="grid grid-cols-3 gap-3">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Masuk (setor)</p><p class="text-lg font-semibold text-emerald-800 tabular-nums">+{{ $rp($t['in']) }}</p></div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Keluar (tarik & potong)</p><p class="text-lg font-semibold text-red-700 tabular-nums">−{{ $rp($t['out']) }}</p></div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Jumlah mutasi</p><p class="text-lg font-semibold tabular-nums">{{ $t['count'] }}</p></div>
        </div>

        <div class="flex flex-col gap-2 lg:flex-row">
            @unless ($this->member)
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama atau kode anggota…" aria-label="Cari anggota"
                       class="flex-1 rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            @endunless
            <select wire:model.live="type" aria-label="Jenis simpanan" class="{{ $select }}">
                <option value="">Semua jenis</option>
                @foreach ($this->types as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <select wire:model.live="kind" aria-label="Arah mutasi" class="{{ $select }}">
                <option value="">Setor, tarik & potong</option>
                @foreach (MemberSavingEntry::TYPES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="period" aria-label="Periode" class="{{ $select }}">
                @foreach (Entries::PERIODS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Riwayat mutasi simpanan">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr><th class="px-4 py-2 font-medium">Waktu</th><th class="px-3 py-2 font-medium">Anggota</th><th class="px-3 py-2 font-medium">Jenis</th>
                            <th class="px-3 py-2 font-medium">Keterangan</th><th class="px-3 py-2 text-right font-medium">Jumlah</th><th class="px-4 py-2 text-right font-medium">Saldo</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->entries as $e)
                            <tr wire:key="me-{{ $e['id'] }}" class="hover:bg-stone-50">
                                <td class="px-4 py-2.5 whitespace-nowrap text-stone-600">{{ \Illuminate\Support\Carbon::parse($e['at'])->translatedFormat('d M Y H:i') }}</td>
                                <td class="px-3 py-2.5">
                                    @if ($e['member'])<a href="{{ route('anggota.show', $e['member']['id']) }}" wire:navigate class="hover:text-emerald-800 hover:underline"><span class="font-mono text-xs text-stone-500">{{ $e['member']['code'] }}</span> {{ $e['member']['name'] }}</a>@else — @endif
                                </td>
                                <td class="px-3 py-2.5">{{ $e['saving_type'] }}</td>
                                <td class="px-3 py-2.5 text-stone-600">
                                    <span @class(['mr-1 rounded px-1.5 py-0.5 text-[11px] font-semibold', 'bg-emerald-100 text-emerald-800' => $e['type'] === 'setor', 'bg-red-100 text-red-800' => $e['type'] === 'tarik', 'bg-amber-100 text-amber-900' => $e['type'] === 'potong'])>{{ $e['type_label'] }}</span>
                                    {{ $e['note'] }}@if ($e['user']) <span class="text-xs text-stone-400">· {{ $e['user'] }}</span>@endif
                                </td>
                                <td @class(['px-3 py-2.5 text-right font-semibold whitespace-nowrap tabular-nums', 'text-emerald-800' => $e['type'] === 'setor', 'text-red-700' => $e['type'] !== 'setor'])>{{ $e['type'] === 'setor' ? '+' : '−' }}{{ $rp($e['amount']) }}</td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap text-stone-600 tabular-nums">{{ $rp($e['balance_after']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-stone-400">Belum ada mutasi simpanan pada saringan ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @if ($this->entries->hasPages())
            <div class="flex items-center justify-between text-sm">
                <button type="button" wire:click="previousPage" @disabled($this->entries->onFirstPage()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Sebelumnya</button>
                <span class="text-stone-500">Halaman {{ $this->entries->currentPage() }} dari {{ $this->entries->lastPage() }}</span>
                <button type="button" wire:click="nextPage" @disabled(! $this->entries->hasMorePages()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Berikutnya</button>
            </div>
        @endif
    </div>
</div>
