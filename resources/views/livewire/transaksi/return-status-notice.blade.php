@use('App\Livewire\Transaksi\Returns')
@php $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.'); $unseen = $this->unseen; $pending = $this->pending; @endphp
<div @class(['border-b border-stone-200 bg-white px-4 py-2 sm:px-6' => $compact && $unseen])>
    @if ($unseen)
        <section @class(['rounded-xl border border-sky-200 bg-sky-50 text-sm text-sky-950', 'px-3 py-2' => $compact, 'px-4 py-3' => ! $compact]) role="status" aria-label="Status pengajuan retur & pembatalan">
            <div class="flex items-start justify-between gap-3">
                <p class="font-semibold">{{ count($unseen) }} pengajuan retur/pembatalan Anda sudah diputus</p>
                <button type="button" wire:click="markSeen" class="shrink-0 text-xs font-medium text-sky-800 hover:underline">Tandai sudah dilihat</button>
            </div>
            <ul class="mt-1.5 space-y-1">
                @foreach (array_slice($unseen, 0, $compact ? 2 : 5) as $r)
                    <li wire:key="ns-{{ $r['id'] }}" class="flex flex-wrap items-baseline gap-x-2">
                        <span class="rounded-md px-1.5 py-0.5 text-[11px] font-semibold {{ Returns::STATUS_TONES[$r['status']] }}">{{ Returns::STATUSES[$r['status']] }}</span>
                        <a href="{{ route('transaksi.retur', ['q' => $r['number'], 'status' => $r['status']]) }}" wire:navigate class="font-mono text-xs font-semibold hover:underline">{{ $r['number'] }}</a>
                        <span class="text-xs">{{ $r['kind'] === 'batal' ? 'Pembatalan' : 'Retur' }} {{ $r['transaction']['invoice'] }} · {{ $rp($r['amount']) }}{{ $r['status'] === 'disetujui' ? ' — kembalikan dana ke pembeli ('.mb_strtolower(Returns::REFUND_METHODS[$r['refund_method']]).')' : '' }}</span>
                        @if ($r['decision_note'])<span class="w-full text-xs text-red-800">Alasan ditolak: {{ $r['decision_note'] }}</span>@endif
                    </li>
                @endforeach
            </ul>
            @if (count($unseen) > ($compact ? 2 : 5))
                <a href="{{ route('transaksi.retur', ['saya' => 1]) }}" wire:navigate class="mt-1 inline-block text-xs font-medium text-sky-800 hover:underline">Lihat semua pengajuan saya →</a>
            @endif
        </section>
    @elseif ($pending && ! $compact)
        <p class="rounded-xl border border-stone-200 bg-white px-4 py-2.5 text-sm text-stone-600" role="status">
            {{ $pending }} pengajuan retur/pembatalan Anda masih menunggu persetujuan.
            <a href="{{ route('transaksi.retur', ['saya' => 1, 'status' => 'menunggu']) }}" wire:navigate class="font-medium text-emerald-800 hover:underline">Lihat</a>
        </p>
    @endif
</div>
