@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $date = fn ($d, $time = false) => \App\Support\Tanggal::format($d, $time);
    ['loan' => $l, 'installments' => $rows, 'disbursements' => $disb, 'summary' => $sum] = $this->detail;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex items-center gap-3 border-b border-stone-200 bg-white px-4 py-3 sm:px-6">
        <a href="{{ route('pinjaman') }}" wire:navigate aria-label="Kembali ke konsol pinjaman"
           class="grid size-9 shrink-0 place-items-center rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></svg>
        </a>
        <p class="text-sm text-stone-500">Pinjaman <span class="text-stone-300">/</span> <span class="font-mono text-stone-800">{{ $l['code'] }}</span></p>
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="space-y-5">
            <section class="rounded-xl border border-stone-200 bg-white p-5" aria-label="Data pinjaman">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h1 class="text-xl font-semibold">{{ $l['member']['name'] ?? '—' }}</h1>
                        <p class="text-sm text-stone-600"><span class="font-mono">{{ $l['member']['code'] ?? '' }}</span>{{ ! empty($l['member']['class']) ? ' · Kelas '.$l['member']['class'] : '' }}{{ $l['member_phone'] ? ' · '.$l['member_phone'] : '' }}</p>
                    </div>
                    <x-pinjaman.status :status="$l['status']" class="text-xs" />
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs text-stone-500">Pokok</dt><dd class="font-medium tabular-nums">{{ $rp($l['principal']) }}</dd></div>
                    <div><dt class="text-xs text-stone-500">Bunga</dt><dd class="font-medium tabular-nums">{{ $rp($l['interest']) }}</dd></div>
                    <div><dt class="text-xs text-stone-500">Total dikembalikan</dt><dd class="font-medium tabular-nums">{{ $rp($l['total']) }}</dd></div>
                    <div><dt class="text-xs text-stone-500">Tenor</dt><dd class="font-medium">{{ $l['tenor_months'] }} bulan · {{ $rp($l['monthly']) }}/bln</dd></div>
                    <div><dt class="text-xs text-stone-500">Metode bayar</dt><dd class="font-medium">{{ $l['payment_label'] }}</dd></div>
                    <div><dt class="text-xs text-stone-500">Diajukan</dt><dd class="font-medium">{{ $date($l['applied_at']) }}</dd></div>
                    @if ($l['decided_at'])
                        <div><dt class="text-xs text-stone-500">Diputuskan</dt><dd class="font-medium">{{ $date($l['decided_at'], true) }}{{ $l['decided_by'] ? ' · '.$l['decided_by'] : '' }}</dd></div>
                    @endif
                    @if ($l['disbursed_at'])
                        <div><dt class="text-xs text-stone-500">Dicairkan</dt><dd class="font-medium">{{ $date($l['disbursed_at']) }}</dd></div>
                    @endif
                    @if ($l['paid_off_at'])
                        <div><dt class="text-xs text-stone-500">Lunas</dt><dd class="font-medium">{{ $date($l['paid_off_at']) }}</dd></div>
                    @endif
                </dl>
                @if ($l['purpose'])
                    <p class="mt-4 rounded-lg bg-stone-50 px-3 py-2 text-sm text-stone-700"><span class="text-xs text-stone-500">Keperluan:</span> {{ $l['purpose'] }}</p>
                @endif
                @if ($l['decision_note'])
                    <p class="mt-2 rounded-lg bg-stone-50 px-3 py-2 text-sm text-stone-700"><span class="text-xs text-stone-500">Catatan keputusan:</span> {{ $l['decision_note'] }}</p>
                @endif
            </section>

            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Jadwal angsuran">
                <h2 class="border-b border-stone-200 px-4 py-3 text-sm font-semibold">Jadwal angsuran</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500">
                            <tr>
                                <th class="px-4 py-2 font-medium">Ke-</th>
                                <th class="px-3 py-2 font-medium">Jatuh tempo</th>
                                <th class="px-3 py-2 text-right font-medium">Tagihan</th>
                                <th class="px-3 py-2 text-right font-medium">Dibayar</th>
                                <th class="px-3 py-2 font-medium">Tanggal bayar</th>
                                <th class="px-4 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($rows as $i)
                                <tr wire:key="inst-{{ $i['id'] }}" @class(['bg-red-50/40' => $i['late']]) data-installment="{{ $i['sequence'] }}">
                                    <td class="px-4 py-2.5 tabular-nums">{{ $i['sequence'] }}</td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">{{ $date($i['due_date']) }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($i['amount']) }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $i['paid_amount'] > 0 ? $rp($i['paid_amount']) : '—' }}</td>
                                    <td class="px-3 py-2.5">{{ $i['paid_at'] ? $date($i['paid_at']) : '—' }}@if ($i['payment_label'])<span class="block text-xs text-stone-500">{{ $i['payment_label'] }}</span>@endif</td>
                                    <td class="px-4 py-2.5">
                                        <span @class(['inline-block rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                                            'bg-emerald-100 text-emerald-800' => $i['status'] === 'dibayar',
                                            'bg-red-100 text-red-800' => $i['status'] !== 'dibayar' && ($i['late'] || $i['status'] === 'menunggak'),
                                            'bg-stone-100 text-stone-700' => $i['status'] !== 'dibayar' && ! $i['late'] && $i['status'] !== 'menunggak'])
                                              data-installment-status="{{ $i['late'] && $i['status'] === 'belum' ? 'terlambat' : $i['status'] }}">{{ $i['late'] && $i['status'] === 'belum' ? 'Lewat jatuh tempo' : $i['status_label'] }}</span>
                                        @if ($i['note'])<span class="block text-xs text-stone-500">{{ $i['note'] }}</span>@endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-10 text-center text-stone-400">Jadwal angsuran dibuat otomatis saat pinjaman dicairkan.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside class="space-y-3">
            @if ($l['status'] === 'disetujui')
                <div class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3" data-ready-banner>
                    <p class="text-sm font-semibold text-indigo-900">Siap dicairkan</p>
                    <p class="mt-1 text-xs text-indigo-900/80">Disetujui {{ $date($l['decided_at']) }}{{ $l['decided_by'] ? ' oleh '.$l['decided_by'] : '' }}. Serahkan dana {{ $rp($l['principal']) }}; jadwal {{ $l['tenor_months'] }} angsuran terbentuk saat pencairan dicatat.</p>
                </div>
            @elseif (in_array($l['status'], ['diajukan'], true))
                <p class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">Menunggu keputusan pengurus sebelum bisa dicairkan.</p>
            @elseif (in_array($l['status'], ['ditolak', 'dibatalkan'], true))
                <p class="rounded-xl border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-stone-700">Pengajuan {{ mb_strtolower($l['status_label']) }} — tidak akan dicairkan.</p>
            @endif
            <div class="rounded-2xl bg-emerald-900 p-5 text-white shadow-sm">
                <p class="text-xs text-emerald-200">Sisa pinjaman</p>
                <p class="text-3xl font-semibold tabular-nums">{{ $rp($l['outstanding']) }}</p>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-emerald-950" role="progressbar" aria-valuenow="{{ $sum['progress'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Persentase terbayar">
                    <div class="h-full bg-emerald-300" style="width: {{ $sum['progress'] }}%"></div>
                </div>
                <p class="mt-2 text-xs text-emerald-200">Terbayar {{ $rp($sum['paid']) }} dari {{ $rp($sum['total']) }} · {{ $sum['paid_count'] }}/{{ $sum['count'] }} angsuran</p>
            </div>
            @if ($sum['next'])
                <div @class(['rounded-xl border px-4 py-3', 'border-red-200 bg-red-50' => $sum['next']['late'], 'border-stone-200 bg-white' => ! $sum['next']['late']])>
                    <p class="text-xs text-stone-500">Angsuran berikutnya (ke-{{ $sum['next']['sequence'] }})</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $rp($sum['next']['amount'] - $sum['next']['paid_amount']) }}</p>
                    <p class="text-xs {{ $sum['next']['late'] ? 'text-red-700' : 'text-stone-500' }}">Jatuh tempo {{ $date($sum['next']['due_date']) }}</p>
                </div>
            @endif
            @if ($sum['late_count'] > 0)
                <p class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $sum['late_count'] }} angsuran lewat jatuh tempo.</p>
            @endif
            <section class="rounded-xl border border-stone-200 bg-white" aria-label="Pencairan">
                <h2 class="border-b border-stone-200 px-4 py-3 text-sm font-semibold">Pencairan</h2>
                <ul class="divide-y divide-stone-100 text-sm">
                    @forelse ($disb as $d)
                        <li class="px-4 py-2.5" wire:key="disb-{{ $d['id'] }}">
                            <p class="flex justify-between"><span>{{ $date($d['disbursed_on']) }}</span><span class="font-medium tabular-nums">{{ $rp($d['amount']) }}</span></p>
                            <p class="text-xs text-stone-500">{{ $d['proof'] ?? 'Tanpa keterangan bukti' }}{{ $d['has_file'] ? ' · ada lampiran' : '' }}{{ $d['user'] ? ' · '.$d['user'] : '' }}</p>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-stone-400">Belum dicairkan.</li>
                    @endforelse
                </ul>
            </section>
        </aside>
    </div>
</div>
