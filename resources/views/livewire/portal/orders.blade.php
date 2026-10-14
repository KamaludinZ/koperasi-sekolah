@php $rp = fn ($n) => \App\Support\Rupiah::format($n); @endphp
<div class="space-y-4">
    <div class="flex items-center justify-between gap-2">
        <h1 class="text-xl font-semibold">Pesanan saya</h1>
        <a href="{{ route('portal.belanja') }}" wire:navigate class="rounded-xl bg-emerald-700 px-3.5 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Belanja lagi</a>
    </div>
    <ul class="divide-y divide-stone-100 overflow-hidden rounded-2xl border border-stone-200 bg-white">
        @forelse ($this->orders as $o)
            <li wire:key="po-{{ $o['id'] }}">
                <a href="{{ route('portal.pesanan.show', $o['id']) }}" wire:navigate class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-stone-50">
                    <span class="min-w-0">
                        <span class="block font-mono text-xs text-stone-500">{{ $o['code'] }}</span>
                        <span class="block text-sm">{{ $o['created_at']->translatedFormat('d M Y H:i') }} · {{ $o['item_count'] }} barang · {{ $o['fulfillment'] === 'antar' ? 'Diantar' : 'Ambil sendiri' }}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        <x-portal.order-status :status="$o['status']" />
                        <span class="mt-1 block text-sm font-semibold tabular-nums">{{ $rp($o['total']) }}</span>
                    </span>
                </a>
            </li>
        @empty
            <li class="px-4 py-10 text-center text-sm text-stone-500">Belum ada pesanan. <a href="{{ route('portal.belanja') }}" wire:navigate class="font-medium text-emerald-800 hover:underline">Mulai belanja</a></li>
        @endforelse
    </ul>
</div>
