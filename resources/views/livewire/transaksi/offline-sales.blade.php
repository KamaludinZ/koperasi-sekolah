@php
    $badge = fn (string $s) => match ($s) {
        'diterima' => 'bg-emerald-100 text-emerald-800', 'perlu_dicek' => 'bg-amber-100 text-amber-900', default => 'bg-red-100 text-red-800',
    };
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Penjualan Offline</h1>
        <p class="text-xs text-stone-500">Penjualan dari kasir offline yang sudah disinkronkan. Kiriman bermasalah tetap tercatat — cek lalu tandai sudah ditinjau.</p>
        <x-transaksi.nav active="transaksi.offline" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="flex flex-wrap items-center gap-1.5 text-xs" role="group" aria-label="Saring status">
            @foreach (\App\Livewire\Transaksi\OfflineSales::FILTERS as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}" data-offline-filter="{{ $key }}"
                        @class(['rounded-full px-3 py-1 font-medium', 'bg-emerald-700 text-white' => $filter === $key, 'border border-stone-300 bg-white text-stone-700 hover:bg-stone-50' => $filter !== $key])>{{ $label }} ({{ $this->counts[$key] }})</button>
            @endforeach
            @if ($this->cashiers)
                <select wire:model.live="cashier" class="ml-auto rounded-lg border border-stone-300 bg-white px-2 py-1 text-xs" aria-label="Kasir">
                    <option value="">Semua kasir</option>
                    @foreach ($this->cashiers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            @endif
        </div>

        <ul class="space-y-2">
            @forelse ($this->sales as $s)
                <li class="rounded-2xl border border-stone-200 bg-white p-4 text-sm" wire:key="os-{{ $s->id }}" data-offline-sale="{{ $s->id }}" data-status="{{ $s->status }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs">{{ $s->local_number }}</span>
                                <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold {{ $badge($s->status) }}">{{ \App\Models\OfflineSale::STATUSES[$s->status] }}</span>
                                @if ($s->reviewed_at)<span class="rounded bg-stone-100 px-1.5 py-0.5 text-[11px] font-semibold text-stone-700">Sudah ditinjau</span>@endif
                                @if ($s->transaction)
                                    <a href="{{ route('transaksi.show', $s->transaction_id) }}" wire:navigate class="font-mono text-xs text-emerald-800 hover:underline">{{ $s->transaction->invoice_number }}</a>
                                @endif
                            </p>
                            <p class="mt-1 text-stone-700">{{ $rp($s->total) }} · {{ \App\Services\OfflineCatalogService::PAYMENT_METHODS[$s->payment_method] ?? $s->payment_method }} · {{ count($s->payload['items'] ?? []) }} jenis barang · kasir {{ $s->user?->name ?? '—' }}</p>
                            <p class="text-xs text-stone-500">Dijual {{ $s->sold_at->translatedFormat('j M Y H:i') }} · masuk server {{ $s->received_at->translatedFormat('j M Y H:i') }} · perangkat {{ \Illuminate\Support\Str::limit($s->device_id, 16) }}</p>
                            @if ($s->status === 'ditolak')
                                <p class="mt-1 text-xs text-red-800" data-offline-reason>Ditolak: {{ $s->reason }}</p>
                            @endif
                            @foreach ($s->flags ?? [] as $flag => $detail)
                                <p class="mt-1 text-xs text-amber-900" data-offline-flag="{{ $flag }}">⚠ {{ \App\Models\OfflineSale::FLAGS[$flag] ?? $flag }}@if ($flag === 'stok_kurang' && is_array($detail)): {{ collect($detail)->map(fn ($d) => $d['name'].' kurang '.$d['shortage'])->join(', ') }}@endif</p>
                            @endforeach
                            @if ($s->reviewed_at)
                                <p class="mt-1 text-xs text-stone-600">Ditinjau {{ $s->reviewer?->name ?? '—' }}, {{ $s->reviewed_at->translatedFormat('j M Y H:i') }}: {{ $s->review_note }}</p>
                            @endif
                        </div>
                        @if ($this->canReview && $s->status !== 'diterima' && ! $s->reviewed_at && $reviewing !== $s->id)
                            <button type="button" wire:click="startReview({{ $s->id }})" class="rounded-lg border border-stone-300 px-2.5 py-1 text-xs font-medium hover:bg-stone-50" data-start-review="{{ $s->id }}">Tandai sudah ditinjau</button>
                        @endif
                    </div>
                    @if ($reviewing === $s->id)
                        <form wire:submit="review" class="mt-3 flex flex-wrap items-end gap-2 border-t border-stone-100 pt-3">
                            <label class="min-w-56 flex-1 text-xs text-stone-600">Catatan tinjauan
                                <input type="text" wire:model="note" maxlength="300" placeholder="{{ $s->status === 'ditolak' ? 'mis. penjualan dicatat ulang manual di kasir' : 'mis. stok opname sudah disesuaikan' }}" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" data-review-note>
                            </label>
                            <button type="submit" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white" data-review-save>Simpan</button>
                            <button type="button" wire:click="$set('reviewing', null)" class="rounded-xl px-3 py-2 text-sm text-stone-600">Batal</button>
                            @error('note')<p class="w-full text-xs text-red-700">{{ $message }}</p>@enderror
                        </form>
                    @endif
                </li>
            @empty
                <li class="rounded-2xl border border-stone-200 bg-white px-4 py-8 text-center text-sm text-stone-500" data-offline-empty>Tidak ada kiriman untuk saringan ini.</li>
            @endforelse
        </ul>
        {{ $this->sales->links() }}
    </div>
</div>
