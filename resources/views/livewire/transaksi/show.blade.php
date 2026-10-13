@php
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    $at = \Illuminate\Support\Carbon::parse($tx['created_at']);
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto"
     x-data
     x-on:keydown.window="
        if (['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName)) return;
        if ($event.key === 'Escape' || $event.key === 'Backspace') { $event.preventDefault(); $refs.back.click() }
        if ($event.key === 'ArrowLeft' && $refs.newer) $refs.newer.click()
        if ($event.key === 'ArrowRight' && $refs.older) $refs.older.click()">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-3 sm:px-6">
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ route('transaksi') }}" wire:navigate x-ref="back" aria-label="Kembali ke riwayat transaksi"
               class="grid size-9 shrink-0 place-items-center rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></svg>
            </a>
            <div class="min-w-0">
                <h1 class="truncate font-mono text-base font-semibold">{{ $tx['invoice_number'] }}</h1>
                <p class="text-xs text-stone-500">{{ $at->translatedFormat('l, d F Y · H:i') }} · {{ $tx['cashier'] }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
        @unless ($at->lt(now()->subDays(\App\Services\TransactionReturnService::limitDays())->startOfDay()))
            <a href="{{ route('transaksi.retur.buat', $tx['id']) }}" wire:navigate
               class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-900 hover:bg-amber-100">Retur barang</a>
            <a href="{{ route('transaksi.retur.batal', $tx['id']) }}" wire:navigate
               class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">Batalkan</a>
        @endunless
        <nav class="flex items-center gap-1" aria-label="Navigasi transaksi">
            @if ($newerId)
                <a href="{{ route('transaksi.show', $newerId) }}" wire:navigate x-ref="newer"
                   class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-50">‹ Lebih baru</a>
            @else
                <span class="rounded-lg border border-stone-200 px-3 py-1.5 text-sm text-stone-300">‹ Lebih baru</span>
            @endif
            @if ($olderId)
                <a href="{{ route('transaksi.show', $olderId) }}" wire:navigate x-ref="older"
                   class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm text-stone-700 hover:bg-stone-50">Lebih lama ›</a>
            @else
                <span class="rounded-lg border border-stone-200 px-3 py-1.5 text-sm text-stone-300">Lebih lama ›</span>
            @endif
        </nav>
        </div>
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 lg:grid-cols-[1fr_auto]">
        <div class="min-w-0 space-y-5">
            {{-- Ringkasan uang --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 sm:col-span-1">
                    <p class="text-xs text-emerald-800">Total</p>
                    <p class="text-2xl font-bold text-emerald-900 tabular-nums">{{ $rp($tx['total']) }}</p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Dibayar</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $rp($tx['paid']) }}</p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Kembalian</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $rp($tx['change']) }}</p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Metode</p>
                    <p class="mt-1"><x-transaksi.method-badge :method="$tx['payment_method']" class="text-xs" /></p>
                </div>
            </div>

            {{-- Rincian barang --}}
            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="items-title">
                <h2 id="items-title" class="border-b border-stone-200 px-4 py-3 text-sm font-semibold">Rincian barang <span class="font-normal text-stone-500">· {{ count($tx['items']) }} jenis, {{ $tx['item_count'] }} barang</span></h2>
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-medium text-stone-500">
                        <tr>
                            <th class="px-4 py-2">Barang</th>
                            <th class="px-4 py-2 text-right">Harga</th>
                            <th class="px-4 py-2 text-right">Jml</th>
                            <th class="px-4 py-2 text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($tx['items'] as $item)
                            <tr>
                                <td class="px-4 py-2.5 font-medium">{{ $item['product_name'] }}</td>
                                <td class="px-4 py-2.5 text-right text-stone-600 tabular-nums">{{ $rp($item['price']) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ $item['quantity'] }}</td>
                                <td class="px-4 py-2.5 text-right font-medium tabular-nums">{{ $rp($item['subtotal']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-stone-200 text-sm">
                        <tr><td colspan="3" class="px-4 pt-3 text-right text-stone-600">Subtotal</td><td class="px-4 pt-3 text-right tabular-nums">{{ $rp($tx['subtotal']) }}</td></tr>
                        @if ($tx['discount'] > 0)
                            @forelse ($tx['discounts'] as $d)
                                <tr>
                                    <td colspan="3" class="px-4 pt-1 text-right text-emerald-800">
                                        <span class="mr-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700">{{ \App\Models\TransactionDiscount::SOURCES[$d['source']] }}</span>
                                        {{ $d['label'] }}@if ($d['reason'])<span class="text-stone-500"> · {{ $d['reason'] }}</span>@endif
                                    </td>
                                    <td class="px-4 pt-1 text-right text-emerald-800 tabular-nums">−{{ $rp($d['amount']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-4 pt-1 text-right text-emerald-800">Diskon @if ($tx['discount_reason'])<span class="text-stone-500">· {{ $tx['discount_reason'] }}</span>@endif</td>
                                    <td class="px-4 pt-1 text-right text-emerald-800 tabular-nums">−{{ $rp($tx['discount']) }}</td>
                                </tr>
                            @endforelse
                        @endif
                        @php $taxName = ($tx['tax_label'] ?? null ?: 'Pajak').(($tx['tax_rate'] ?? 0) > 0 ? ' '.str_replace('.', ',', (string) ($tx['tax_rate'] + 0)).'%' : ''); @endphp
                        <tr><td colspan="3" class="px-4 pt-1 text-right text-stone-600">{{ $taxName }}</td><td class="px-4 pt-1 text-right tabular-nums">{{ $rp($tx['tax']) }}</td></tr>
                        @if (($tx['rounding'] ?? 0) !== 0)
                            <tr><td colspan="3" class="px-4 pt-1 text-right text-stone-600">Pembulatan</td><td class="px-4 pt-1 text-right tabular-nums">{{ $tx['rounding'] > 0 ? '+' : '−' }}{{ $rp(abs($tx['rounding'])) }}</td></tr>
                        @endif
                        <tr><td colspan="3" @class(['px-4 pt-1 text-right font-semibold', 'pb-3' => ! ($tx['tax_included'] ?? 0)])>Total</td><td @class(['px-4 pt-1 text-right font-bold tabular-nums', 'pb-3' => ! ($tx['tax_included'] ?? 0)])>{{ $rp($tx['total']) }}</td></tr>
                        @if (($tx['tax_included'] ?? 0) > 0)
                            <tr><td colspan="3" class="px-4 pt-1 pb-3 text-right text-xs text-stone-500">Sudah termasuk {{ $taxName }}</td><td class="px-4 pt-1 pb-3 text-right text-xs text-stone-500 tabular-nums">{{ $rp($tx['tax_included']) }}</td></tr>
                        @endif
                    </tfoot>
                </table>
            </section>

            {{-- Info transaksi --}}
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 rounded-xl border border-stone-200 bg-white px-4 py-4 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-stone-500">Waktu</dt><dd>{{ $at->translatedFormat('l, d F Y · H:i:s') }}</dd></div>
                <div><dt class="text-xs text-stone-500">Petugas</dt><dd>{{ $tx['cashier'] }}</dd></div>
                <div><dt class="text-xs text-stone-500">Anggota</dt><dd>{{ $tx['member'] ?? 'Umum (bukan anggota)' }}@if ($tx['member_info']['member_code'] ?? null) <span class="font-mono text-xs text-stone-500">{{ $tx['member_info']['member_code'] }}</span>@endif</dd></div>
                @if ($tx['savings'] ?? null)
                    <div><dt class="text-xs text-stone-500">Saldo tabungan</dt><dd class="tabular-nums">{{ $rp($tx['savings']['balance_before']) }} → <strong>{{ $rp($tx['savings']['balance_after']) }}</strong></dd></div>
                @endif
                @if ($tx['credit'] ?? null)
                    <div><dt class="text-xs text-stone-500">Bon</dt><dd><span class="font-mono text-xs">{{ $tx['credit']['number'] }}</span> · {{ $tx['credit']['status_label'] }} · jatuh tempo {{ \Illuminate\Support\Carbon::parse($tx['credit']['due_at'])->translatedFormat('d M Y') }}@if ($tx['credit']['remaining']) · sisa {{ $rp($tx['credit']['remaining']) }}@endif</dd></div>
                @endif
                <div><dt class="text-xs text-stone-500">No. invoice</dt><dd class="font-mono">{{ $tx['invoice_number'] }}</dd></div>
            </dl>
            <p class="text-xs text-stone-400">Gunakan ← → untuk berpindah transaksi, Esc untuk kembali.</p>
        </div>

        {{-- Struk --}}
        <aside class="lg:w-[64mm]" aria-label="Struk">
            <div class="rounded-xl bg-stone-200/70 p-3">
                <div class="mx-auto w-[58mm] max-w-full rounded-sm bg-white p-3 shadow-sm">
                    @include('livewire.kasir.partials.receipt', [
                        'tx' => [...$tx, 'payment_method_label' => \App\Services\CheckoutService::PAYMENT_LABELS[$tx['payment_method']] ?? $tx['payment_method']],
                        'profile' => app(\App\Services\ReceiptPresenter::class)->profile(),
                    ])
                </div>
            </div>
            <button type="button" x-on:click="window.print()"
                    class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-stone-300 bg-white py-2.5 text-sm font-semibold text-stone-800 hover:bg-stone-50">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 9V3h12v6M6 18H4a1 1 0 01-1-1v-6a2 2 0 012-2h14a2 2 0 012 2v6a1 1 0 01-1 1h-2M6 14h12v7H6z"/></svg>
                Cetak ulang struk
            </button>
        </aside>
    </div>
</div>
