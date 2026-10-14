@php
    $m = $this->matrix;
    $cell = ['disetor' => ['✓', 'bg-emerald-100 text-emerald-800', 'Sudah disetor'], 'belum' => ['•', 'bg-amber-100 text-amber-900', 'Dicatat, belum diposting'], 'kosong' => ['–', 'bg-red-50 text-red-700', 'Belum dicatat']];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs text-stone-500"><a href="{{ route('simpanan.setoran') }}" wire:navigate class="hover:text-emerald-800 hover:underline">Setoran bulanan</a> / Rekap status</p>
                <h1 class="text-lg font-semibold">Rekap setoran bulanan</h1>
            </div>
        </div>
        <x-simpanan.nav active="simpanan.setoran" />
    </header>
    <div class="space-y-4 px-4 py-5 sm:px-6">
        @if ($m['periods'])
            @php $last = end($m['periods']); $t = $m['totals'][$last['key']]; @endphp
            <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Ringkasan {{ $last['full'] }}">
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Bulan ini</p><p class="text-lg font-semibold">{{ $last['full'] }}</p></div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Sudah disetor</p><p class="text-2xl font-bold text-emerald-800 tabular-nums" data-month-paid>{{ $t['paid'] }} <span class="text-sm font-normal text-stone-500">/ {{ $t['eligible'] }}</span></p></div>
                <div @class(['rounded-xl border px-4 py-3', 'border-red-200 bg-red-50' => $t['missing'], 'border-stone-200 bg-white' => ! $t['missing']])><p class="text-xs text-stone-600">Belum disetor</p><p class="text-2xl font-bold tabular-nums">{{ $t['missing'] }}</p></div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Total setoran</p><p class="text-xl font-bold tabular-nums" data-month-amount>{{ \App\Support\Rupiah::format($t['amount']) }}</p></div>
            </section>
        @endif
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="typeId" aria-label="Jenis simpanan" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                @foreach ($this->types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
            </select>
            <select wire:model.live="months" aria-label="Rentang" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                @foreach (\App\Livewire\Simpanan\DuesRecap::RANGES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="onlyMissing" class="accent-emerald-700"> Hanya yang belum lengkap</label>
            <p class="ml-auto flex flex-wrap gap-3 text-xs text-stone-500">
                @foreach ($cell as [$sym, $tone, $label])<span class="flex items-center gap-1"><span class="grid size-5 place-items-center rounded {{ $tone }}">{{ $sym }}</span>{{ $label }}</span>@endforeach
            </p>
        </div>
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Matriks setoran">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-xs text-stone-500">
                        <tr><th class="px-4 py-2 text-left font-medium">Guru/karyawan</th>
                            @foreach ($m['periods'] as $p)<th class="px-2 py-2 text-center font-medium whitespace-nowrap">{{ $p['label'] }}</th>@endforeach
                            <th class="px-4 py-2 text-right font-medium">Belum</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($m['rows'] as $r)
                            <tr wire:key="mx-{{ $r['member_id'] }}">
                                <td class="px-4 py-2 whitespace-nowrap"><a href="{{ route('anggota.show', $r['member_id']) }}" wire:navigate class="hover:text-emerald-800 hover:underline"><span class="font-mono text-xs text-stone-500">{{ $r['code'] }}</span> {{ $r['name'] }}</a></td>
                                @foreach ($m['periods'] as $p)
                                    @php $c = $r['cells'][$p['key']]; @endphp
                                    <td class="px-2 py-2 text-center">
                                        @if ($c)
                                            <a href="{{ route('simpanan.setoran', ['bulan' => $p['key'], 'jenis' => $typeId]) }}" wire:navigate title="{{ $cell[$c][2] }}" data-cell="{{ $c }}"
                                               class="mx-auto grid size-6 place-items-center rounded font-semibold {{ $cell[$c][1] }}">{{ $cell[$c][0] }}</a>
                                        @else
                                            <span class="text-stone-300" title="Belum bergabung">·</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td @class(['px-4 py-2 text-right font-semibold tabular-nums', 'text-red-700' => $r['missing'], 'text-stone-400' => ! $r['missing']])>{{ $r['missing'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($m['periods']) + 2 }}" class="px-4 py-10 text-center text-stone-400">{{ $onlyMissing ? 'Semua setoran lengkap.' : 'Tidak ada anggota guru/karyawan aktif.' }}</td></tr>
                        @endforelse
                    </tbody>
                    @if ($m['rows'])
                        <tfoot class="border-t-2 border-stone-200 bg-stone-50 text-xs">
                            <tr><th class="px-4 py-2 text-left font-medium text-stone-600">Sudah disetor</th>
                                @foreach ($m['periods'] as $p)<td class="px-2 py-2 text-center font-semibold tabular-nums">{{ $m['totals'][$p['key']]['paid'] }}/{{ $m['totals'][$p['key']]['eligible'] }}</td>@endforeach
                                <td></td></tr>
                            <tr><th class="px-4 py-2 text-left font-medium text-stone-600">Total</th>
                                @foreach ($m['periods'] as $p)<td class="px-2 py-2 text-center whitespace-nowrap tabular-nums" data-total="{{ $p['key'] }}">{{ number_format($m['totals'][$p['key']]['amount'] / 1000, 0, ',', '.') }}rb</td>@endforeach
                                <td></td></tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </section>
    </div>
</div>
