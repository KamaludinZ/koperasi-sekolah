@use('App\Livewire\Anggota\Piutang')
@php
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    $date = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y');
    $select = 'rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600';
    $s = $this->stats;
    $badge = ['lewat' => ['Lewat jatuh tempo', 'bg-red-100 text-red-800'], 'minggu' => ['Segera jatuh tempo', 'bg-amber-100 text-amber-900'], 'berjalan' => ['Berjalan', 'bg-stone-100 text-stone-700']];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Anggota & Tabungan</h1>
                <p class="text-xs text-stone-500">Bon (bayar nanti) anggota yang belum lunas dan riwayat pelunasannya.</p>
            </div>
            <span class="rounded bg-stone-100 px-2 py-1 text-xs font-medium text-stone-500" title="Menunggu backend bon">Data contoh</span>
        </div>
        <x-anggota.nav active="anggota.piutang" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Total piutang</p>
                <p class="text-xl font-bold tabular-nums sm:text-2xl">{{ $rp($s['total']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Anggota berbon</p>
                <p class="text-2xl font-bold tabular-nums">{{ $s['members'] }}</p>
            </div>
            <div @class(['rounded-xl border px-4 py-3', 'border-red-200 bg-red-50' => $s['late_members'], 'border-stone-200 bg-white' => ! $s['late_members']])>
                <p @class(['text-xs', 'text-red-800' => $s['late_members'], 'text-stone-500' => ! $s['late_members']])>Lewat jatuh tempo</p>
                <p @class(['text-xl font-bold tabular-nums sm:text-2xl', 'text-red-800' => $s['late_members']])>{{ $rp($s['late_total']) }}</p>
                <p class="text-xs text-stone-500">{{ $s['late_members'] }} anggota</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Jatuh tempo ≤ 7 hari</p>
                <p class="text-2xl font-bold tabular-nums">{{ $s['week_members'] }} <span class="text-sm font-normal text-stone-500">anggota</span></p>
            </div>
        </div>

        <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-200/60 p-1" role="tablist" aria-label="Tampilan piutang">
            @foreach (Piutang::TABS as $key => $label)
                <button type="button" role="tab" wire:click="setTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $tab === $key, 'text-stone-600 hover:text-stone-900' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <label class="relative flex-1 sm:min-w-56">
                <span class="sr-only">Cari anggota</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="search" placeholder="Cari nama atau kode anggota…"
                       class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
            <select wire:model.live="type" aria-label="Jenis anggota" class="{{ $select }}">
                <option value="">Siswa & guru</option>
                @foreach (\App\Livewire\Anggota\Index::TYPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            @if ($tab === 'daftar')
                <select wire:model.live="status" aria-label="Status" class="{{ $select }}">
                    @foreach (Piutang::STATUSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            @else
                <select wire:model.live="kind" aria-label="Jenis catatan" class="{{ $select }}">
                    @foreach (Piutang::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                <select wire:model.live="period" aria-label="Periode" class="{{ $select }}">
                    @foreach (Piutang::PERIODS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            @endif
        </div>

        @if ($tab === 'daftar')
            @php $rows = $this->list; @endphp
            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar piutang">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[46rem] text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500">
                            <tr>
                                <th class="px-4 py-2 font-medium">Anggota</th>
                                <th class="px-3 py-2 font-medium">Status</th>
                                <th class="px-3 py-2 font-medium">Jatuh tempo tertua</th>
                                <th class="px-3 py-2 font-medium">Pemakaian batas</th>
                                <th class="px-4 py-2 text-right font-medium">Sisa bon</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse (array_slice($rows, 0, $limit) as $r)
                                @php [$label, $tone] = $badge[$r['status']]; $pct = $r['limit'] ? min(100, (int) round($r['outstanding'] / $r['limit'] * 100)) : 0; @endphp
                                <tr wire:key="d-{{ $r['id'] }}" class="hover:bg-stone-50">
                                    <td class="px-4 py-2.5">
                                        <a href="{{ route('anggota.show', $r['id']) }}" wire:navigate class="font-medium text-emerald-800 hover:underline">{{ $r['name'] }}</a>
                                        <span class="block text-xs text-stone-500"><span class="font-mono">{{ $r['member_code'] }}</span> · {{ $r['type_label'] }}{{ $r['class'] ? ' '.$r['class'] : '' }} · {{ $r['bills'] }} bon</span>
                                    </td>
                                    <td class="px-3 py-2.5"><span class="rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $tone }}">{{ $label }}</span></td>
                                    <td @class(['px-3 py-2.5 whitespace-nowrap', 'font-semibold text-red-700' => $r['status'] === 'lewat', 'text-stone-600' => $r['status'] !== 'lewat'])>{{ $date($r['oldest_due_at']) }}</td>
                                    <td class="px-3 py-2.5">
                                        <span class="flex items-center gap-2">
                                            <span class="h-1.5 w-20 overflow-hidden rounded-full bg-stone-100" aria-hidden="true"><span @class(['block h-full rounded-full', 'bg-red-500' => $pct >= 90, 'bg-amber-500' => $pct < 90]) style="width: {{ $pct }}%"></span></span>
                                            <span class="text-xs text-stone-600 tabular-nums">{{ $pct }}% dari {{ $rp($r['limit']) }}</span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ $rp($r['outstanding']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-10 text-center text-stone-400">{{ $search !== '' || $type !== '' || $status !== '' ? 'Tidak ada piutang yang cocok.' : 'Belum ada anggota yang punya bon.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (count($rows) > $limit)
                    <button type="button" wire:click="more" class="block w-full border-t border-stone-100 py-2.5 text-sm font-medium text-emerald-800 hover:bg-stone-50">Tampilkan lebih banyak ({{ count($rows) - $limit }} lagi)</button>
                @endif
            </section>
        @else
            @php $h = $this->history; @endphp
            <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                <p>Bon baru <strong class="tabular-nums">{{ $rp($h['bon']) }}</strong></p>
                <p>Dilunasi <strong class="text-emerald-800 tabular-nums">{{ $rp($h['paid']) }}</strong></p>
            </div>
            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Riwayat piutang">
                <ul class="divide-y divide-stone-100">
                    @forelse (array_slice($h['rows'], 0, $limit) as $e)
                        <li wire:key="h-{{ $e['id'] }}" class="flex items-start justify-between gap-3 px-4 py-2.5 text-sm">
                            <span class="flex min-w-0 items-start gap-3">
                                <span @class(['mt-0.5 grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold', 'bg-amber-100 text-amber-900' => $e['kind'] === 'bon', 'bg-emerald-100 text-emerald-800' => $e['kind'] === 'pelunasan'])>{{ $e['kind'] === 'bon' ? '+' : '✓' }}</span>
                                <span class="min-w-0">
                                    <span class="block">{{ $e['kind'] === 'bon' ? 'Bon baru' : 'Pelunasan'.($e['method'] === 'tabungan' ? ' (potong tabungan)' : ' (tunai)') }} ·
                                        <a href="{{ route('anggota.show', $e['member']['id']) }}" wire:navigate class="font-medium text-emerald-800 hover:underline">{{ $e['member']['name'] }}</a></span>
                                    <span class="block text-xs text-stone-500">{{ $date($e['date']) }} · <span class="font-mono">{{ $e['number'] }}</span>@if ($e['note']) · {{ $e['note'] }}@endif</span>
                                </span>
                            </span>
                            <span @class(['shrink-0 font-semibold tabular-nums', 'text-emerald-800' => $e['kind'] === 'pelunasan'])>{{ $e['kind'] === 'bon' ? '+' : '−' }}{{ $rp($e['amount']) }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-10 text-center text-sm text-stone-400">Tidak ada catatan pada periode ini.</li>
                    @endforelse
                </ul>
                @if (count($h['rows']) > $limit)
                    <button type="button" wire:click="more" class="block w-full border-t border-stone-100 py-2.5 text-sm font-medium text-emerald-800 hover:bg-stone-50">Tampilkan lebih banyak ({{ count($h['rows']) - $limit }} lagi)</button>
                @endif
            </section>
        @endif
    </div>
</div>
