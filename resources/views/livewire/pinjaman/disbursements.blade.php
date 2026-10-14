@php $rp = fn ($n) => \App\Support\Rupiah::format($n); $ready = $this->ready; $recent = $this->recent; @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Pencairan Pinjaman</h1>
            <p class="text-xs text-stone-500">Pengajuan yang sudah disetujui menunggu dana diserahkan. Setelah dicairkan, jadwal angsuran terbentuk otomatis.</p>
        </div>
        <x-pinjaman.nav active="pinjaman.pencairan" />
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 lg:grid-cols-[minmax(0,1fr)_380px]">
        <section aria-labelledby="ready-title">
            <h2 id="ready-title" class="mb-2 text-sm font-semibold">Siap dicairkan <span class="ml-1 rounded-full bg-indigo-100 px-1.5 text-xs text-indigo-800 tabular-nums">{{ count($ready) }}</span></h2>
            @if ($ready === [])
                <div class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-12 text-center" data-empty="siap-cair">
                    <p class="text-sm font-medium text-stone-700">Tidak ada pinjaman yang menunggu pencairan</p>
                    <p class="mt-1 text-sm text-stone-500">Pengajuan yang disetujui di halaman Pengajuan akan muncul di sini.</p>
                </div>
            @else
                <ul class="space-y-3">
                    @foreach ($ready as $l)
                        <li wire:key="ready-{{ $l['id'] }}" class="rounded-xl border border-indigo-200 bg-white p-4" data-ready="{{ $l['code'] }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-semibold">{{ $l['member']['name'] ?? '—' }}</p>
                                    <p class="text-xs text-stone-500"><span class="font-mono">{{ $l['member']['code'] ?? '' }}</span> · <a href="{{ route('pinjaman.show', $l['id']) }}" wire:navigate class="font-mono text-emerald-800 hover:underline">{{ $l['code'] }}</a></p>
                                </div>
                                <p class="text-right"><span class="block text-lg font-semibold tabular-nums">{{ $rp($l['principal']) }}</span><span class="text-xs text-stone-500">dana diserahkan</span></p>
                            </div>
                            <p class="mt-2 text-sm text-stone-600">Bunga {{ $rp($l['interest']) }} · {{ $l['tenor_months'] }} bulan · {{ $rp($l['monthly']) }}/bln · {{ $l['payment_label'] }}</p>
                            <p class="mt-1 text-xs text-stone-500">Disetujui @tanggal($l['decided_at']){{ $l['decision_note'] ? ' · '.$l['decision_note'] : '' }}</p>
                            <a href="{{ route('pinjaman.show', $l['id']) }}" wire:navigate class="mt-3 inline-block text-sm font-semibold text-emerald-800 hover:underline">Buka rincian &rarr;</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="h-fit rounded-xl border border-stone-200 bg-white" aria-labelledby="recent-title">
            <h2 id="recent-title" class="border-b border-stone-200 px-4 py-3 text-sm font-semibold">Pencairan terbaru</h2>
            <ul class="divide-y divide-stone-100 text-sm">
                @forelse ($recent as $d)
                    <li wire:key="disb-{{ $d['id'] }}" class="px-4 py-2.5" data-disbursement="{{ $d['code'] }}">
                        <p class="flex justify-between gap-3"><a href="{{ route('pinjaman.show', $d['loan_id']) }}" wire:navigate class="truncate hover:underline">{{ $d['member'] ?? '—' }}</a><span class="font-medium tabular-nums">{{ $rp($d['amount']) }}</span></p>
                        <p class="text-xs text-stone-500"><span class="font-mono">{{ $d['code'] }}</span> · @tanggal($d['disbursed_on']){{ $d['proof'] ? ' · '.$d['proof'] : '' }}{{ $d['has_file'] ? ' · ada lampiran' : '' }}</p>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-stone-400">Belum ada pencairan.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
