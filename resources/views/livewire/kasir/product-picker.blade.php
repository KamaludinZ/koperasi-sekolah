<section class="flex min-h-0 flex-col"
         x-data
         x-on:keydown.window="if ((($event.key === '/' && !['INPUT','TEXTAREA'].includes($event.target.tagName)) || $event.key === 'F2')) { $event.preventDefault(); $refs.search.focus(); $refs.search.select() }">
    <div class="space-y-3 border-b border-stone-200 bg-white/60 px-4 py-4 sm:px-6">
        <label class="relative block">
            <span class="sr-only">Cari barang</span>
            <svg class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" autofocus x-ref="search"
                   wire:model.live.debounce.150ms="search"
                   wire:keydown.enter.prevent="pickFromSearch"
                   wire:keydown.escape="$set('search', '')"
                   placeholder="Cari nama atau kode barang…"
                   class="w-full rounded-xl border border-stone-300 bg-white py-3 pr-16 pl-11 text-base shadow-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            <kbd class="pointer-events-none absolute top-1/2 right-3.5 hidden -translate-y-1/2 rounded border border-stone-300 bg-stone-50 px-1.5 py-0.5 font-mono text-[11px] text-stone-500 sm:block">F2</kbd>
        </label>
        {{-- Umpan balik scan: bunyi pendek (berhasil/gagal) + pesan yang hilang sendiri. --}}
        <div x-data="{ beep(type) { try { const a = new (window.AudioContext || window.webkitAudioContext)(); const o = a.createOscillator(); const g = a.createGain(); o.frequency.value = type === 'success' ? 880 : 220; g.gain.value = .05; o.connect(g); g.connect(a.destination); o.start(); o.stop(a.currentTime + (type === 'success' ? .08 : .25)); } catch (e) {} } }"
             x-on:scan-feedback.window="beep($event.detail.type)" aria-live="polite">
            @if ($scan)
                <p wire:key="scan-{{ md5($scan['text']) }}" x-data x-init="setTimeout(() => $el.remove(), 4000)"
                   @class(['rounded-lg px-3 py-1.5 text-sm', 'bg-emerald-50 text-emerald-900' => $scan['type'] === 'success', 'bg-red-50 text-red-800' => $scan['type'] === 'error', 'bg-amber-50 text-amber-900' => $scan['type'] === 'info'])
                   data-scan="{{ $scan['type'] }}">{{ $scan['text'] }}</p>
            @endif
        </div>
        <div class="flex gap-2 overflow-x-auto pb-1">
            <button type="button" wire:click="$set('category', '')"
                    @class(['shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium transition',
                            'bg-emerald-900 text-white' => $category === '',
                            'border border-stone-300 bg-white text-stone-700 hover:border-stone-400' => $category !== ''])>Semua</button>
            @foreach ($categories as $cat)
                <button type="button" wire:click="setCategory(@js($cat))"
                        @class(['shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium transition',
                                'bg-emerald-900 text-white' => $category === $cat,
                                'border border-stone-300 bg-white text-stone-700 hover:border-stone-400' => $category !== $cat])>{{ $cat }}</button>
            @endforeach
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto px-4 pt-4 pb-36 sm:px-6 md:pb-4">
        @if (count($this->products) === 0)
            <div class="flex flex-col items-center gap-1 py-16 text-center">
                <p class="text-sm font-medium text-stone-600">Barang tidak ditemukan</p>
                <p class="text-xs text-stone-400">Coba kata kunci lain atau periksa kode barang.</p>
            </div>
        @else
            <p class="mb-3 text-xs text-stone-500">{{ count($this->products) }} barang · tekan Enter untuk memilih hasil tunggal atau kode yang cocok</p>
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                @foreach ($this->products as $product)
                    @php
                        $out = $product['stock'] === 0;
                        $low = ! $out && $product['stock'] <= $product['min_stock'];
                    @endphp
                    <li wire:key="product-{{ $product['id'] }}">
                        <button type="button" @disabled($out) wire:click="pick({{ $product['id'] }})"
                                @class(['group flex h-full w-full flex-col rounded-xl border bg-white p-3.5 text-left shadow-sm transition hover:border-emerald-500 hover:shadow-md active:scale-[.98] disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-stone-200 disabled:hover:shadow-sm',
                                        'border-emerald-500 ring-2 ring-emerald-500/20' => $lastPicked === $product['id'],
                                        'border-stone-200' => $lastPicked !== $product['id']])>
                            <span class="font-mono text-[11px] tracking-wide text-stone-400">{{ $product['code'] }}</span>
                            <span class="mt-1 line-clamp-2 text-sm leading-snug font-medium">{{ $product['name'] }}</span>
                            <span class="mt-auto flex items-end justify-between gap-2 pt-3">
                                <span class="font-semibold text-emerald-800 tabular-nums">Rp{{ number_format($product['price'], 0, ',', '.') }}</span>
                                <span @class(['rounded px-1.5 py-0.5 text-[11px] font-medium tabular-nums',
                                              'bg-red-100 text-red-700' => $out,
                                              'bg-amber-100 text-amber-800' => $low,
                                              'bg-stone-100 text-stone-600' => ! $out && ! $low])>
                                    {{ $out ? 'Habis' : $product['stock'].' '.$product['unit'] }}
                                </span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
