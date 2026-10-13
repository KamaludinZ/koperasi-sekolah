{{-- Promo otomatis yang berlaku untuk isi keranjang (sudah memotong total) + petunjuk agar dapat promo --}}
@php $promo = $this->promos; @endphp
@if ($summary['count'] > 0 && ($promo['applied'] !== [] || $promo['hints'] !== []))
    <section class="mx-5 mb-3 rounded-xl border border-emerald-200 bg-emerald-50/60 text-sm" aria-labelledby="cart-promo-title" x-data="{ open: true }">
        <button type="button" x-on:click="open = !open" x-bind:aria-expanded="open" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left">
            <span id="cart-promo-title" class="flex items-center gap-1.5 font-semibold text-emerald-950">
                <svg class="size-4 text-emerald-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 15l6-6M9.5 9.5h.01M14.5 14.5h.01M7 3h10l4 4v10l-4 4H7l-4-4V7z"/></svg>
                Promo otomatis
                @if ($promo['applied'] !== [])<span class="rounded-full bg-emerald-700 px-1.5 text-[11px] text-white tabular-nums">{{ count($promo['applied']) }}</span>@endif
            </span>
            <span class="flex items-center gap-1.5">
                @if ($promo['total'] > 0)<span class="text-xs font-semibold text-emerald-800 tabular-nums">hemat {{ $rp($promo['total']) }}</span>@endif
                <svg class="size-4 text-stone-500 transition" x-bind:class="open || 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg>
            </span>
        </button>
        <div x-show="open" x-collapse class="space-y-2 border-t border-emerald-200/70 px-3 py-2">
            @foreach ($promo['applied'] as $p)
                <div class="flex items-start justify-between gap-2" wire:key="cart-promo-{{ $p['id'] }}">
                    <div class="min-w-0">
                        <p class="truncate font-medium text-emerald-950">{{ $p['name'] }}</p>
                        <p class="truncate text-xs text-stone-600">{{ implode(', ', $p['items']) }}</p>
                    </div>
                    <span class="shrink-0 font-semibold text-emerald-800 tabular-nums">−{{ $rp($p['saving']) }}</span>
                </div>
            @endforeach
            @foreach ($promo['hints'] as $h)
                <p class="flex items-start gap-1.5 text-xs text-amber-900">
                    <svg class="mt-0.5 size-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18h6M10 22h4M12 2a7 7 0 00-4 12.7V17h8v-2.3A7 7 0 0012 2z"/></svg>
                    <span><span class="font-medium">{{ $h['name'] }}:</span> {{ $h['message'] }}</span>
                </p>
            @endforeach
            @if ($promo['total'] > 0)<p class="text-[11px] text-stone-500">Potongan promo otomatis sudah dikurangkan dari total.</p>@endif
        </div>
    </section>
@endif
