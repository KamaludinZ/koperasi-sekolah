<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <h1 class="text-lg font-semibold">Pusat Panduan</h1>
        <p class="text-xs text-stone-500">Cara memakai tiap menu: ringkasan singkat dan langkah praktis berurutan.</p>
    </header>

    @php $hay = fn ($k, $e) => \App\Support\GuideCatalog::haystack($k, $e); @endphp
    <div class="grid flex-1 gap-5 px-4 py-5 sm:px-6 lg:grid-cols-[19rem_minmax(0,1fr)]" x-data="guideSearch(@js($search))" data-guide-root>
        {{-- Daftar panduan --}}
        <aside class="space-y-3 lg:sticky lg:top-0 lg:self-start" aria-label="Daftar panduan">
            <label class="relative block">
                <span class="sr-only">Cari panduan</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" x-ref="input" x-model="q" x-on:input.debounce.300ms="$wire.set('search', q)"
                       x-on:keydown.enter.prevent="openFirst()" x-on:keydown.escape.prevent="clear()" value="{{ $search }}"
                       maxlength="60" placeholder="Cari, mis. tutup shift, retur, bon…  ( / )" aria-describedby="guide-search-hint"
                       class="w-full rounded-xl border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm" data-guide-search>
            </label>
            <p id="guide-search-hint" class="px-1 text-[11px] text-stone-500" x-show="q.trim() !== ''" x-cloak aria-live="polite">
                <span x-text="count"></span> panduan cocok · Enter membuka yang pertama · Esc mengosongkan
            </p>

            @forelse ($this->groups as $group => $items)
                <section data-guide-group>
                    <p class="mb-1 px-1 text-[11px] font-semibold tracking-wider text-stone-500 uppercase">{{ $group }}</p>
                    <ul class="space-y-0.5">
                        @foreach ($items as $key => $e)
                            <li wire:key="g-{{ $key }}" data-guide-row>
                                <button type="button" wire:click="open('{{ $key }}')" data-guide-item="{{ $key }}" data-hay="{{ $hay($key, $e) }}" @if ($currentKey === $key) aria-current="true" @endif
                                        @class(['w-full rounded-lg px-3 py-2 text-left text-sm', 'bg-emerald-50 font-semibold text-emerald-900 ring-1 ring-emerald-200' => $currentKey === $key, 'text-stone-700 hover:bg-stone-100' => $currentKey !== $key])>
                                    {{ $e['menu'] ? (\App\Support\Menu::ITEMS[$e['menu']]['label'] ?? $e['title']) : 'Akun saya' }}
                                    <span class="block truncate text-xs font-normal text-stone-500">{{ $e['title'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <p class="rounded-lg bg-stone-100 px-3 py-6 text-center text-sm text-stone-500" data-guide-empty>Tidak ada panduan yang cocok dengan “{{ $search }}”.</p>
            @endforelse
        </aside>

        {{-- Isi panduan atau ikhtisar --}}
        @if (! $current)
            <section class="min-w-0" aria-label="Ikhtisar panduan" data-guide-overview>
                <p class="mb-3 text-sm text-stone-600">{{ $search !== '' ? 'Hasil pencarian “'.$search.'”' : 'Pilih menu untuk melihat langkah-langkahnya.' }}</p>
                @foreach ($this->groups as $group => $items)
                    <div data-guide-group>
                    <h2 class="mt-4 mb-2 text-xs font-semibold tracking-wider text-stone-500 uppercase first:mt-0">{{ $group }}</h2>
                    <div class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                        @foreach ($items as $key => $e)
                            @php $icon = $e['menu'] ? (\App\Support\Menu::ITEMS[$e['menu']]['icon'] ?? null) : 'M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z'; @endphp
                            <button type="button" wire:click="open('{{ $key }}')" wire:key="card-{{ $key }}" data-guide-card="{{ $key }}" data-hay="{{ $hay($key, $e) }}"
                                    class="flex flex-col gap-2 rounded-2xl border border-stone-200 bg-white p-4 text-left transition hover:border-emerald-400 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-emerald-600">
                                <span class="flex items-center gap-2">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-800" aria-hidden="true">
                                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="{{ $icon }}"/></svg>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-stone-900">{{ $e['menu'] ? \App\Support\Menu::ITEMS[$e['menu']]['label'] : 'Akun saya' }}</span>
                                        <span class="block truncate text-xs text-stone-500">{{ $e['title'] }}</span>
                                    </span>
                                </span>
                                <span class="line-clamp-2 text-xs text-stone-600">{{ $e['summary'] }}</span>
                                <span class="text-[11px] font-medium text-emerald-800">{{ count($e['steps']) }} langkah →</span>
                            </button>
                        @endforeach
                    </div>
                    </div>
                @endforeach
            </section>
        @else
            <article class="min-w-0 rounded-2xl border border-stone-200 bg-white p-5 sm:p-6" aria-labelledby="guide-title" data-guide="{{ $currentKey }}">
                <button type="button" wire:click="closeGuide" class="mb-3 text-xs font-medium text-stone-500 hover:text-stone-800" data-guide-back>← Semua panduan</button>
                <p class="text-xs font-semibold tracking-wider text-emerald-700 uppercase">{{ $current['menu'] ? (\App\Support\Menu::ITEMS[$current['menu']]['label'] ?? '') : 'Akun saya' }}</p>
                <h2 id="guide-title" class="mt-1 text-xl font-semibold text-stone-900">{{ $current['title'] }}</h2>
                <p class="mt-2 max-w-prose text-sm leading-relaxed text-stone-700" data-guide-summary>{{ $current['summary'] }}</p>

                <h3 class="mt-6 text-sm font-semibold text-stone-900">Langkah-langkah</h3>
                <ol class="mt-3 space-y-3" data-guide-steps>
                    @foreach ($current['steps'] as $i => $step)
                        <li class="flex gap-3">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-emerald-700 text-xs font-bold text-white" aria-hidden="true">{{ $i + 1 }}</span>
                            <div class="min-w-0 pt-0.5 text-sm text-stone-800">
                                {{ $step['text'] }}
                                @if (($step['route'] ?? null) && \App\Support\Menu::canReach($step['route']))
                                    <a href="{{ route($step['route']) }}" wire:navigate class="ml-1 font-medium whitespace-nowrap text-emerald-800 hover:underline">Buka →</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>

                @if ($current['tips'])
                    <div class="mt-6 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900" data-guide-tips>
                        <p class="font-semibold">Tips</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5">
                            @foreach ($current['tips'] as $tip)<li>{{ $tip }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                @php $related = collect($current['related'])->filter(fn ($l, $r) => \App\Support\Menu::canReach($r)); @endphp
                @if ($related->isNotEmpty())
                    <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-stone-100 pt-4 text-sm">
                        <span class="text-stone-500">Halaman terkait:</span>
                        @foreach ($related as $r => $label)
                            <a href="{{ route($r) }}" wire:navigate class="rounded-lg border border-stone-300 px-2.5 py-1 font-medium text-stone-700 hover:bg-stone-50">{{ $label }}</a>
                        @endforeach
                    </div>
                @endif
            </article>
        @endif
    </div>
</div>
