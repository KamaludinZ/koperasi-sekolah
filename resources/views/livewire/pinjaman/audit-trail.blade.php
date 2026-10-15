@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $logs = $this->logs;
    $c = $this->counts;
    $sel = $this->selected;
    $filtered = $group !== '' || $period !== '30-hari' || $actor !== '' || $loanId > 0 || $search !== '';
    $select = 'rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
    $tone = ['pengajuan' => 'bg-sky-100 text-sky-900', 'pencairan' => 'bg-indigo-100 text-indigo-900', 'angsuran' => 'bg-emerald-100 text-emerald-900',
        'tunggakan' => 'bg-red-100 text-red-800', 'ekspor' => 'bg-stone-100 text-stone-700'];
    $val = fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'ya' : 'tidak') : (string) ($v ?? '—'));
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Jejak Audit Pinjaman</h1>
            <p class="text-xs text-stone-500">Siapa melakukan apa dan kapan pada pinjaman: pengajuan, pencairan, angsuran & koreksi, tunggakan, unduhan rekap. Jejak tidak bisa diubah atau dihapus.</p>
        </div>
        <x-pinjaman.nav active="pinjaman.audit" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="inline-flex gap-1 rounded-xl border border-stone-200 bg-white p-1" role="tablist" aria-label="Tampilan">
            @foreach (['jejak' => 'Jejak peristiwa', 'transaksi' => 'Pencairan & angsuran'] as $k => $t)
                <button type="button" role="tab" wire:click="setView('{{ $k }}')" aria-selected="{{ $view === $k ? 'true' : 'false' }}" data-view="{{ $k }}"
                        @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-emerald-700 text-white' => $view === $k, 'text-stone-600 hover:bg-stone-50' => $view !== $k])>{{ $t }}</button>
            @endforeach
        </div>

        @if ($view === 'jejak')
        <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-100 p-1" role="tablist" aria-label="Kelompok peristiwa">
            <button type="button" role="tab" wire:click="setGroup('')" aria-selected="{{ $group === '' ? 'true' : 'false' }}" data-group=""
                    @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white shadow-sm' => $group === '', 'text-stone-600' => $group !== ''])>Semua <span class="text-xs text-stone-500 tabular-nums">{{ $c['semua'] }}</span></button>
            @foreach (\App\Services\LoanAuditQuery::GROUPS as $k => $g)
                <button type="button" role="tab" wire:click="setGroup('{{ $k }}')" aria-selected="{{ $group === $k ? 'true' : 'false' }}" data-group="{{ $k }}"
                        @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white shadow-sm' => $group === $k, 'text-stone-600' => $group !== $k])>{{ $g['label'] }} <span class="text-xs text-stone-500 tabular-nums">{{ $c[$k] }}</span></button>
            @endforeach
            <button type="button" role="tab" wire:click="setGroup('perhatian')" aria-selected="{{ $group === 'perhatian' ? 'true' : 'false' }}" data-group="perhatian"
                    @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white shadow-sm' => $group === 'perhatian', 'text-red-700' => $group !== 'perhatian'])>Perlu perhatian <span class="text-xs tabular-nums">{{ $c['perhatian'] }}</span></button>
        </div>
        @else
        <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-100 p-1" role="tablist" aria-label="Jenis transaksi">
            @foreach (['' => 'Semua'] + \App\Services\LoanLedgerQuery::TYPES as $k => $t)
                <button type="button" role="tab" wire:click="$set('ledgerType', '{{ $k }}')" aria-selected="{{ $ledgerType === $k ? 'true' : 'false' }}" data-ledger-type="{{ $k }}"
                        @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white shadow-sm' => $ledgerType === $k, 'text-stone-600' => $ledgerType !== $k])>{{ $t }}</button>
            @endforeach
        </div>
        @endif

        <div class="flex flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center">
            <select wire:model.live="period" aria-label="Periode" class="{{ $select }}">
                @foreach (\App\Services\AuditQuery::PERIODS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="actor" aria-label="Petugas" @class([$select, 'hidden' => $view !== 'jejak'])>
                <option value="">Semua petugas</option>
                @foreach ($this->actors as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
            </select>
            <label class="relative lg:w-72">
                <span class="sr-only">Cari jejak</span>
                <input type="search" wire:model.live.debounce.250ms="search" maxlength="100" placeholder="Cari kode pinjaman / anggota / uraian…" class="{{ $select }} w-full">
            </label>
            @if ($this->loanLabel)
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 text-sm text-emerald-900" data-loan-filter>Pinjaman {{ $this->loanLabel }}
                    <button type="button" wire:click="$set('loanId', 0)" class="text-emerald-700" aria-label="Lepas saringan pinjaman">×</button></span>
            @endif
            @if ($filtered)
                <button type="button" wire:click="resetFilters" class="px-1 text-sm font-medium text-emerald-700 hover:underline">Hapus filter</button>
            @endif
        </div>

        @if ($view === 'transaksi')
            @php $ledger = $this->ledger; $tot = \App\Services\LoanLedgerQuery::totals($this->ledgerAll); @endphp
            <section class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Total transaksi">
                <div class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3" data-ledger-total="pencairan"><p class="text-xs text-indigo-900">Dana dicairkan</p><p class="text-lg font-bold tabular-nums">{{ $rp($tot['disbursed']) }}</p></div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3" data-ledger-total="angsuran"><p class="text-xs text-emerald-800">Angsuran sah diterima</p><p class="text-lg font-bold tabular-nums">{{ $rp($tot['collected']) }}</p></div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3" data-ledger-total="koreksi"><p class="text-xs text-stone-500">Pembayaran dikoreksi (batal)</p><p class="text-lg font-bold tabular-nums">{{ $tot['voided_count'] }} · {{ $rp($tot['voided']) }}</p></div>
            </section>
            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Riwayat pencairan dan angsuran">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[52rem] text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500">
                            <tr><th class="px-4 py-2 font-medium">Tanggal</th><th class="px-3 py-2 font-medium">Jenis</th><th class="px-3 py-2 font-medium">Pinjaman</th><th class="px-3 py-2 text-right font-medium">Nominal</th><th class="px-3 py-2 font-medium">Metode · rujukan</th><th class="px-4 py-2 font-medium">Petugas</th></tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($ledger as $x)
                                <tr wire:key="led-{{ $x['key'] }}" @class(['text-stone-400' => ! $x['valid']]) data-ledger="{{ $x['type'] }}">
                                    <td class="px-4 py-2.5 whitespace-nowrap">@tanggal($x['date'])<span class="block text-xs text-stone-400">dicatat @tanggal($x['recorded_at'], true)</span></td>
                                    <td class="px-3 py-2.5">
                                        <span @class(['rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap', 'bg-indigo-100 text-indigo-900' => $x['type'] === 'pencairan',
                                            'bg-emerald-100 text-emerald-900' => $x['type'] === 'angsuran', 'bg-amber-100 text-amber-900' => $x['type'] === 'pengganti', 'bg-red-100 text-red-800' => $x['type'] === 'dibatalkan'])>{{ $x['label'] }}</span>
                                        @if ($x['sequences'])<span class="block text-xs text-stone-500">angsuran ke-{{ implode(', ', $x['sequences']) }}</span>@endif
                                        @if ($x['void'])<span class="block text-xs text-red-700" data-void-reason>Dikoreksi {{ $x['void']['by'] ?? '' }} @tanggal($x['void']['at'], true): {{ $x['void']['reason'] }}</span>@endif
                                        @if ($x['replaces_id'])<span class="block text-xs text-amber-800">menggantikan pembayaran #{{ $x['replaces_id'] }}</span>@endif
                                    </td>
                                    <td class="px-3 py-2.5">@if ($x['loan'])<a href="{{ route('pinjaman.show', $x['loan']['id']) }}" wire:navigate class="font-mono text-xs font-semibold text-emerald-800 hover:underline">{{ $x['loan']['code'] }}</a><span class="block text-xs text-stone-500">{{ $x['loan']['member'] }}</span>@endif</td>
                                    <td @class(['px-3 py-2.5 text-right font-semibold tabular-nums whitespace-nowrap', 'line-through' => ! $x['valid'], 'text-indigo-900' => $x['amount'] < 0 && $x['valid']])>{{ $x['amount'] < 0 ? '−'.$rp(-$x['amount']) : '+'.$rp($x['amount']) }}</td>
                                    <td class="px-3 py-2.5 text-xs">{{ $x['method'] ?? '—' }}{{ $x['reference'] ? ' · '.$x['reference'] : '' }}</td>
                                    <td class="px-4 py-2.5 text-xs">{{ $x['user'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-12 text-center text-stone-400" data-empty="riwayat-transaksi">Tidak ada pencairan atau angsuran pada periode/saringan ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($ledger->hasPages())
                    <div class="flex items-center justify-between border-t border-stone-100 px-4 py-2 text-sm">
                        <button type="button" wire:click="previousPage" @disabled($ledger->onFirstPage()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Sebelumnya</button>
                        <span class="text-stone-500">Halaman {{ $ledger->currentPage() }} dari {{ $ledger->lastPage() }}</span>
                        <button type="button" wire:click="nextPage" @disabled(! $ledger->hasMorePages()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Berikutnya</button>
                    </div>
                @endif
            </section>
        @else
        <div class="grid gap-4 lg:grid-cols-[1fr_22rem]">
            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar jejak audit pinjaman">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500">
                            <tr><th class="px-4 py-2 font-medium">Waktu</th><th class="px-3 py-2 font-medium">Peristiwa</th><th class="px-3 py-2 font-medium">Uraian</th><th class="px-4 py-2 font-medium">Petugas</th></tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($logs as $l)
                                <tr wire:key="audit-{{ $l['id'] }}" wire:click="show({{ $l['id'] }})" @class(['cursor-pointer hover:bg-stone-50', 'bg-emerald-50/60' => $selectedId === $l['id']]) data-audit="{{ $l['event'] }}">
                                    <td class="px-4 py-2.5 whitespace-nowrap text-xs text-stone-600">@tanggal($l['at'], true)</td>
                                    <td class="px-3 py-2.5"><span class="rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $tone[$l['group']] ?? 'bg-stone-100 text-stone-700' }}">{{ $l['label'] }}</span>
                                        @if ($l['notable'])<span class="ml-1 text-red-600" title="Perlu perhatian" aria-label="Perlu perhatian">●</span>@endif</td>
                                    <td class="px-3 py-2.5">{{ $l['description'] }}
                                        @if ($l['reason'])<span class="block text-xs text-stone-500">Alasan: {{ $l['reason'] }}</span>@endif</td>
                                    <td class="px-4 py-2.5 text-xs">{{ $l['actor'] ?? 'Sistem' }}<span class="block text-stone-500">{{ $l['actor_role'] }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-12 text-center text-stone-400" data-empty="audit-pinjaman">
                                    {{ $filtered ? 'Tidak ada jejak yang cocok dengan filter.' : 'Belum ada aktivitas pinjaman pada periode ini.' }}
                                    @if ($filtered) <button type="button" wire:click="resetFilters" class="font-medium text-emerald-700 hover:underline">Hapus filter</button>@endif
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($logs->hasPages())
                    <div class="flex items-center justify-between border-t border-stone-100 px-4 py-2 text-sm">
                        <button type="button" wire:click="previousPage" @disabled($logs->onFirstPage()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Sebelumnya</button>
                        <span class="text-stone-500">Halaman {{ $logs->currentPage() }} dari {{ $logs->lastPage() }}</span>
                        <button type="button" wire:click="nextPage" @disabled(! $logs->hasMorePages()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Berikutnya</button>
                    </div>
                @endif
            </section>

            <aside class="rounded-xl border border-stone-200 bg-white p-4 text-sm lg:sticky lg:top-4 lg:self-start" aria-label="Rincian jejak" data-audit-detail>
                @if ($sel)
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="font-semibold">{{ $sel['label'] }}</h2>
                        <button type="button" wire:click="closeDetail" class="text-stone-400 hover:text-stone-700" aria-label="Tutup rincian">×</button>
                    </div>
                    <p class="mt-1 text-stone-700">{{ $sel['description'] }}</p>
                    <dl class="mt-3 space-y-1 text-xs">
                        <div class="flex justify-between gap-2"><dt class="text-stone-500">Waktu</dt><dd>@tanggal($sel['at'], true)</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-stone-500">Petugas</dt><dd>{{ $sel['actor'] ?? 'Sistem' }}{{ $sel['actor_role'] ? ' ('.$sel['actor_role'].')' : '' }}</dd></div>
                        @if ($sel['ip'])<div class="flex justify-between gap-2"><dt class="text-stone-500">IP</dt><dd class="font-mono">{{ $sel['ip'] }}</dd></div>@endif
                        @if ($sel['amount'] !== null)<div class="flex justify-between gap-2"><dt class="text-stone-500">Nominal</dt><dd class="tabular-nums">{{ $rp($sel['amount']) }}</dd></div>@endif
                        @if ($sel['loan'])<div class="flex justify-between gap-2"><dt class="text-stone-500">Pinjaman</dt>
                            <dd><a href="{{ route('pinjaman.show', $sel['loan']['id']) }}" wire:navigate class="text-emerald-700 hover:underline">{{ $sel['loan']['label'] }}</a>
                                · <button type="button" wire:click="$set('loanId', {{ $sel['loan']['id'] }})" class="text-emerald-700 hover:underline">semua jejaknya</button></dd></div>@endif
                        @if ($sel['reason'])<div class="flex justify-between gap-2"><dt class="text-stone-500">Alasan</dt><dd>{{ $sel['reason'] }}</dd></div>@endif
                    </dl>
                    @if ($sel['old'] || $sel['new'])
                        <table class="mt-3 w-full text-xs" data-audit-diff>
                            <thead class="text-left text-stone-500"><tr><th class="py-1 font-medium">Kolom</th><th class="py-1 font-medium">Sebelum</th><th class="py-1 font-medium">Sesudah</th></tr></thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach (array_unique([...array_keys($sel['old'] ?? []), ...array_keys($sel['new'] ?? [])]) as $field)
                                    <tr><td class="py-1 pr-2 font-mono text-stone-600">{{ $field }}</td>
                                        <td class="py-1 pr-2 break-all text-red-700">{{ $val($sel['old'][$field] ?? null) }}</td>
                                        <td class="py-1 break-all text-emerald-800">{{ $val($sel['new'][$field] ?? null) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @else
                    <p class="text-stone-500">Pilih satu baris untuk melihat rincian: nilai sebelum/sesudah, alasan, dan alamat IP.</p>
                @endif
            </aside>
        </div>
        @endif
    </div>
</div>
