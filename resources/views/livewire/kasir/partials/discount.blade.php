{{-- Diskon manual transaksi: baris diskon terpasang, tombol tambah, atau form nominal/persen + alasan --}}
@php
    $rules = \App\Support\MockData::manualDiscountRules();
    $isPercent = $discountType === 'persen';
@endphp
@if ($summary['count'] > 0)
    @if ($editingDiscount)
        <form wire:submit="applyDiscount" class="-mx-1 space-y-2.5 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3" aria-label="Diskon manual"
              x-data x-init="$nextTick(() => $refs.discount.focus())" x-on:keydown.escape.stop="$wire.cancelDiscount()">
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm font-semibold text-emerald-950">Diskon manual</p>
                <div class="flex rounded-lg bg-white p-0.5 ring-1 ring-stone-200" role="group" aria-label="Jenis diskon">
                    @foreach (['persen' => '%', 'nominal' => 'Rp'] as $type => $label)
                        <button type="button" wire:click="$set('discountType', '{{ $type }}')" aria-pressed="{{ $discountType === $type ? 'true' : 'false' }}"
                                @class(['rounded-md px-2.5 py-0.5 text-xs font-semibold', 'bg-emerald-700 text-white' => $discountType === $type, 'text-stone-600' => $discountType !== $type])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <div class="relative">
                @unless ($isPercent)<span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-stone-500">Rp</span>@endunless
                <input type="number" inputmode="numeric" min="1" step="{{ $isPercent ? 1 : 500 }}" x-ref="discount" wire:model.live.debounce.300ms="discountValue"
                       aria-label="Besar diskon {{ $isPercent ? 'dalam persen' : 'dalam rupiah' }}" @error('discountValue') aria-invalid="true" @enderror
                       @class(['w-full [appearance:textfield] rounded-lg border border-stone-300 bg-white py-2 text-right text-base font-semibold tabular-nums outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15 [&::-webkit-inner-spin-button]:appearance-none',
                               'pr-8 pl-3' => $isPercent, 'pr-3 pl-10' => ! $isPercent])>
                @if ($isPercent)<span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-stone-500">%</span>@endif
            </div>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($isPercent ? [5, 10] : [1000, 2000, 5000] as $n)
                    <button type="button" wire:click="$set('discountValue', {{ $n }})" class="rounded-md border border-stone-300 bg-white px-2 py-0.5 text-xs font-medium tabular-nums hover:border-emerald-600">{{ $isPercent ? $n.'%' : $rp($n) }}</button>
                @endforeach
                @if ($discountValue)
                    <span class="ml-auto self-center text-xs text-emerald-900 tabular-nums">−{{ $rp(\App\Livewire\Kasir\Cart::discountAmount(['type' => $discountType, 'value' => (int) $discountValue], $summary['subtotal'])) }}</span>
                @endif
            </div>
            @error('discountValue') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            <div>
                <input type="text" wire:model="discountReason" maxlength="100" placeholder="Alasan diskon{{ $rules['reason_required'] ? '' : ' (opsional)' }}" aria-label="Alasan diskon"
                       @error('discountReason') aria-invalid="true" @enderror
                       class="w-full rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                <div class="mt-1.5 flex flex-wrap gap-1">
                    @foreach (\App\Livewire\Kasir\Cart::DISCOUNT_REASONS as $reason)
                        <button type="button" wire:click="$set('discountReason', @js($reason))" class="rounded-full bg-white px-2 py-0.5 text-[11px] text-stone-600 ring-1 ring-stone-200 hover:text-emerald-900 hover:ring-emerald-400">{{ $reason }}</button>
                    @endforeach
                </div>
                @error('discountReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <p class="text-[11px] text-stone-500">Batas kasir {{ $rules['max_percent'] }}% · maks. {{ $rp($rules['max_amount']) }}. Lebih dari itu perlu persetujuan admin.</p>
            <div class="flex gap-2">
                <button type="button" wire:click="cancelDiscount" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="applyDiscount" class="flex-1 rounded-lg bg-emerald-700 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Terapkan diskon</button>
            </div>
        </form>
    @elseif ($discount)
        <div class="flex items-start justify-between gap-2 text-emerald-800">
            <button type="button" wire:click="editDiscount" class="min-w-0 text-left hover:underline" title="Ubah diskon">
                <span>Diskon{{ $discount['type'] === 'persen' ? ' '.$discount['value'].'%' : '' }}</span>
                @if ($discount['reason'])<span class="block truncate text-xs text-stone-500">{{ $discount['reason'] }}</span>@endif
            </button>
            <span class="flex shrink-0 items-center gap-1.5">
                <span class="font-medium tabular-nums">−{{ $rp($summary['discount']) }}</span>
                <button type="button" wire:click="removeDiscount" class="text-stone-400 hover:text-red-600" aria-label="Hapus diskon">
                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </span>
        </div>
    @elseif ($rules['enabled'])
        <button type="button" wire:click="editDiscount" class="flex items-center gap-1 text-sm font-medium text-emerald-800 hover:underline">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Tambah diskon
        </button>
    @endif
@endif
