{{-- Kode kupon/voucher: form dengan umpan balik langsung, atau kupon yang sudah diterima (pratinjau) --}}
@if ($summary['count'] > 0)
    @php $c = $this->coupon; @endphp
    @if ($editingCoupon)
        <form wire:submit="applyCoupon" class="-mx-1 rounded-xl border border-stone-200 bg-stone-50 p-3" aria-label="Kode kupon"
              x-data x-init="$nextTick(() => $refs.code.focus())" x-on:keydown.escape.stop="$wire.cancelCoupon()">
            <label for="coupon-code" class="mb-1.5 block text-sm font-semibold">Kode kupon / voucher</label>
            <div class="flex gap-2">
                <input id="coupon-code" type="text" x-ref="code" wire:model="couponInput" maxlength="30" autocomplete="off" autocapitalize="characters" spellcheck="false"
                       placeholder="mis. MPLS2026" aria-describedby="coupon-feedback" @error('couponInput') aria-invalid="true" @enderror
                       x-on:input="$event.target.value = $event.target.value.toUpperCase()"
                       @class(['min-w-0 flex-1 rounded-lg border bg-white px-3 py-1.5 font-mono text-sm font-semibold tracking-wide uppercase outline-none placeholder:font-sans placeholder:font-normal placeholder:tracking-normal placeholder:normal-case focus:ring-4',
                               'border-red-400 focus:border-red-500 focus:ring-red-500/15' => $errors->has('couponInput'),
                               'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15' => ! $errors->has('couponInput')])>
                <button type="submit" wire:loading.attr="disabled" wire:target="applyCoupon" class="rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
                    <span wire:loading.remove wire:target="applyCoupon">Pakai</span><span wire:loading wire:target="applyCoupon">Memeriksa…</span>
                </button>
            </div>
            <div id="coupon-feedback" aria-live="polite">
                @error('couponInput')
                    <p class="mt-1.5 flex items-start gap-1.5 text-xs text-red-700">
                        <svg class="mt-px size-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>{{ $message }}
                    </p>
                @enderror
            </div>
            <button type="button" wire:click="cancelCoupon" class="mt-2 text-xs font-medium text-stone-500 hover:text-stone-800">Batal</button>
        </form>
    @elseif ($c)
        <div @class(['-mx-1 rounded-lg px-2 py-1.5', 'bg-emerald-50' => $c['ok'], 'bg-amber-50' => ! $c['ok']]) aria-live="polite">
            <div class="flex items-center justify-between gap-2">
                <span class="flex min-w-0 items-center gap-1.5">
                    <code class="rounded border border-dashed border-emerald-400 bg-white px-1.5 font-mono text-xs font-bold text-emerald-900">{{ $appliedCoupon }}</code>
                    @if ($c['ok'])
                        <span class="truncate text-xs text-emerald-900">−{{ $rp($c['amount']) }}</span>
                    @endif
                </span>
                <button type="button" wire:click="removeCoupon" class="shrink-0 text-stone-400 hover:text-red-600" aria-label="Lepas kupon {{ $appliedCoupon }}">
                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <p @class(['mt-0.5 text-xs', 'text-emerald-800' => $c['ok'], 'text-amber-900' => ! $c['ok']])>
                {{ $c['ok'] ? $c['message'] : 'Kupon belum bisa dipakai: '.$c['message'] }}
            </p>
            <p class="text-[11px] text-stone-500">Pratinjau: kupon belum memotong total.</p>
        </div>
    @else
        <button type="button" wire:click="editCoupon" class="flex items-center gap-1 text-sm font-medium text-emerald-800 hover:underline">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9a3 3 0 010 6v3h18v-3a3 3 0 010-6V6H3zM13 6v2m0 3v2m0 3v2"/></svg>
            Pakai kode kupon
        </button>
    @endif
@endif
