<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex flex-wrap items-end justify-between gap-3 border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Pusat Panduan</h1>
            <p class="text-xs text-stone-500">Cara memakai tiap menu: ringkasan singkat dan langkah praktis berurutan.</p>
        </div>
        @if ($this->canPreview())
            <a href="{{ route('panduan.pembaruan') }}" wire:navigate class="text-sm font-medium text-emerald-800 hover:underline print:hidden" data-guide-updates-link>Pembaruan panduan →</a>
        @endif
    </header>

    @php
        $hay = fn ($k, $e) => \App\Support\GuideCatalog::haystack($k, $e);
        $roleLabel = fn ($r) => \App\Models\Role::LABELS[$r] ?? $r;
        $roleList = fn (array $roles, string $glue = ' dan ') => collect($roles)->map($roleLabel)->join(', ', $glue);
        $whose = $preview !== '' ? 'peran yang ditinjau' : 'peran Anda';
        $lock = '<svg class="inline size-3 -translate-y-px" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>';
    @endphp
    <div class="grid flex-1 gap-5 px-4 py-5 sm:px-6 lg:grid-cols-[19rem_minmax(0,1fr)]" x-data="guideSearch(@js($search))" data-guide-root>
        {{-- Daftar panduan --}}
        {{-- di layar kecil daftar disembunyikan saat membaca satu panduan (kembali lewat "Semua panduan") --}}
        <aside @class(['space-y-3 lg:sticky lg:top-0 lg:block lg:self-start print:hidden', 'hidden' => $current !== null]) aria-label="Daftar panduan"
               x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)" x-on:keydown.home="if ($event.target.dataset.guideItem) { $event.preventDefault(); move('first') }"
               x-on:keydown.end="if ($event.target.dataset.guideItem) { $event.preventDefault(); move('last') }">
            {{-- Panduan sesuai peran: gabungan menu semua peran, bisa disaring per peran bila lebih dari satu --}}
            @if ($this->canPreview())
                <div class="rounded-xl border border-stone-200 bg-white px-3 py-2.5 text-xs text-stone-700" data-guide-preview-controls>
                    <p class="font-semibold text-stone-900">Pratinjau sebagai peran</p>
                    <p class="mt-0.5 text-stone-500">Lihat daftar panduan dengan izin peran lain (gabungan bila memilih lebih dari satu).</p>
                    <div class="mt-2 flex flex-wrap gap-1" role="group" aria-label="Pratinjau izin peran">
                        @foreach (\App\Models\Role::LABELS as $r => $label)
                            @php $on = in_array($r, explode(',', $preview), true); @endphp
                            <button type="button" wire:click="togglePreview('{{ $r }}')" aria-pressed="{{ $on ? 'true' : 'false' }}" data-guide-preview-role="{{ $r }}"
                                    @class(['rounded-full px-2.5 py-0.5 font-medium', 'bg-sky-700 text-white' => $on, 'border border-stone-300 bg-white text-stone-700 hover:bg-stone-100' => ! $on])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
            @if ($preview !== '')
                <div class="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2.5 text-xs text-sky-950" role="status" data-guide-preview-banner>
                    <p><strong>Mode pratinjau</strong> — daftar ini memakai izin tiruan, bukan izin akun Anda.</p>
                    <button type="button" wire:click="endPreview" class="mt-1 font-semibold text-sky-800 hover:underline" data-guide-preview-end>Kembali ke izin saya</button>
                </div>
            @endif
            <div class="rounded-xl border border-emerald-100 bg-emerald-50/60 px-3 py-2.5 text-xs text-emerald-950" data-guide-roles>
                <p>Panduan untuk peran <strong>{{ collect($this->myRoles)->map($roleLabel)->join(', ', ' & ') ?: '—' }}</strong> · {{ count($this->sources) }} menu</p>
                @if (count($this->myRoles) > 1)
                    <div class="mt-2 flex flex-wrap gap-1" role="group" aria-label="Saring panduan per peran">
                        <button type="button" wire:click="$set('role', '')" aria-pressed="{{ $role === '' ? 'true' : 'false' }}" data-guide-role=""
                                @class(['rounded-full px-2.5 py-0.5 font-medium', 'bg-emerald-700 text-white' => $role === '', 'border border-emerald-200 bg-white text-emerald-900 hover:bg-emerald-100' => $role !== ''])>Semua peran</button>
                        @foreach ($this->roleCounts as $r => $n)
                            <button type="button" wire:click="$set('role', '{{ $r }}')" aria-pressed="{{ $role === $r ? 'true' : 'false' }}" data-guide-role="{{ $r }}"
                                    @class(['rounded-full px-2.5 py-0.5 font-medium', 'bg-emerald-700 text-white' => $role === $r, 'border border-emerald-200 bg-white text-emerald-900 hover:bg-emerald-100' => $role !== $r])>{{ $roleLabel($r) }} ({{ $n }})</button>
                        @endforeach
                    </div>
                @endif
            </div>
            <label class="relative block">
                <span class="sr-only">Cari panduan</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" x-ref="input" x-model="q" x-on:input.debounce.300ms="$wire.set('search', q)"
                       x-on:keydown.enter.prevent="openFirst()" x-on:keydown.escape.prevent="clear()" value="{{ $search }}"
                       maxlength="60" placeholder="Cari, mis. tutup shift, retur, bon…  ( / )" aria-describedby="guide-search-hint"
                       class="w-full rounded-xl border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm" data-guide-search>
            </label>
            @if ($this->restrictedCount > 0)
                <label class="flex items-center gap-2 px-1 text-xs text-stone-600" data-restricted-toggle>
                    <input type="checkbox" wire:model.live="showRestricted" class="size-4 rounded border-stone-300 text-emerald-700">
                    Tampilkan menu terbatas ({{ $this->restrictedCount }})
                </label>
                @if ($showRestricted)
                    <p class="px-1 text-[11px] text-stone-500" data-restricted-legend>{!! $lock !!} Terbatas = menu di luar {{ $whose }}; hanya ringkasan & peran pemiliknya yang ditampilkan.</p>
                @endif
            @endif
            <p id="guide-search-hint" class="px-1 text-[11px] text-stone-500" data-guide-hint @if ($search === '') hidden @endif aria-live="polite">
                <span data-guide-count>{{ collect($this->groups)->flatten(1)->count() }}</span> panduan cocok · Enter membuka yang pertama · Esc mengosongkan
            </p>

            @forelse ($this->groups as $group => $items)
                <section data-guide-group aria-label="{{ $group }}">
                    <p class="mb-1 px-1 text-[11px] font-semibold tracking-wider text-stone-500 uppercase">{{ $group }}</p>
                    <ul class="space-y-0.5">
                        @foreach ($items as $key => $e)
                            <li wire:key="g-{{ $key }}" data-guide-row>
                                <button type="button" wire:click="open('{{ $key }}')" data-guide-item="{{ $key }}" data-hay="{{ $hay($key, $e) }}" @if ($currentKey === $key) aria-current="true" @endif
                                        @unless ($this->allowed($key)) aria-label="{{ $e['menu'] ? \App\Support\Menu::ITEMS[$e['menu']]['label'] : '' }} — terbatas, untuk peran {{ $roleList($this->requiredFor($key)) ?: 'lain' }}" @endunless
                                        @class(['w-full rounded-lg px-3 py-2 text-left text-sm', 'bg-emerald-50 font-semibold text-emerald-900 ring-1 ring-emerald-200' => $currentKey === $key, 'text-stone-700 hover:bg-stone-100' => $currentKey !== $key && $this->allowed($key), 'text-stone-500 hover:bg-stone-100' => $currentKey !== $key && ! $this->allowed($key)])>
                                    {{ $e['menu'] ? (\App\Support\Menu::ITEMS[$e['menu']]['label'] ?? $e['title']) : 'Akun saya' }}
                                    @if ($via = $this->via($key))<span class="ml-1 rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-800 ring-1 ring-sky-200" data-guide-via="{{ $key }}" title="Terbuka lewat peran {{ collect($via)->map($roleLabel)->join(', ') }}">{{ collect($via)->map($roleLabel)->join(' · ') }}</span>@endif
                                    @if ($this->canPreview() && ($e['overridden'] ?? []))<span class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-800" data-guide-edited-badge="{{ $key }}" title="Bagian disunting: {{ collect($e['overridden'])->map(fn ($f) => mb_strtolower(\App\Models\GuideOverride::FIELDS[$f] ?? $f))->join(', ') }}">Disunting</span>@endif
                                    @if ($this->canPreview() && ($e['stale'] ?? false))<span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900" data-guide-stale-badge="{{ $key }}">Perlu ditinjau</span>@endif
                                    @unless ($this->allowed($key))<span class="ml-1 rounded bg-stone-200 px-1.5 py-0.5 text-[10px] font-semibold text-stone-600" data-restricted="{{ $key }}" title="Untuk peran {{ $roleList($this->requiredFor($key)) ?: '—' }}">{!! $lock !!} Terbatas</span>@endunless
                                    <span class="block truncate text-xs font-normal text-stone-500">{{ $e['title'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
            @endforelse
            <p data-guide-when-empty @if (! ($this->groups === [] && $search !== '')) hidden @endif
               class="rounded-lg bg-stone-100 px-3 py-4 text-center text-xs text-stone-500" data-guide-empty-list>Tidak ada di daftar.</p>
        </aside>

        {{-- Isi panduan atau ikhtisar --}}
        @if (! $current)
            <section class="min-w-0" aria-label="Ikhtisar panduan" data-guide-overview>
                {{-- Pencarian tanpa hasil (tampil seketika saat mengetik maupun dari tautan ?q=) --}}
                <div data-guide-when-empty @if (! ($this->groups === [] && $search !== '')) hidden @endif
                     class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-10 text-center" role="status" data-guide-empty>
                    <span class="mx-auto grid size-12 place-items-center rounded-full bg-stone-100 text-stone-400" aria-hidden="true">
                        <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M8.5 11h5"/></svg>
                    </span>
                    <p class="mt-3 font-semibold text-stone-900">Panduan tidak ditemukan</p>
                    <p class="mt-1 text-sm text-stone-600">Tidak ada panduan yang cocok dengan “<span data-guide-query>{{ $search }}</span>”. Coba kata lain yang lebih umum atau periksa ejaannya.</p>
                    @if ($this->restrictedMatches > 0)
                        <p class="mt-3 text-sm text-stone-700" data-guide-empty-restricted>
                            Ada {{ $this->restrictedMatches }} panduan menu terbatas yang cocok.
                            <button type="button" wire:click="$set('showRestricted', true)" class="font-semibold text-emerald-800 hover:underline">Tampilkan</button>
                        </p>
                    @endif
                    <div class="mt-4 flex flex-wrap justify-center gap-1.5" aria-label="Saran kata kunci">
                        @foreach (\App\Livewire\Panduan\Index::SUGGESTIONS as $sugg)
                            <button type="button" x-on:click="q = @js($sugg); $wire.set('search', @js($sugg))" data-guide-suggestion="{{ $sugg }}"
                                    class="rounded-full border border-stone-300 px-3 py-1 text-xs text-stone-700 hover:border-emerald-600 hover:bg-emerald-50">{{ $sugg }}</button>
                        @endforeach
                    </div>
                    <button type="button" x-on:click="clear()" class="mt-4 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800" data-guide-clear>Hapus pencarian</button>
                </div>
                @if (count($this->groups) > 1)
                    <nav class="mb-3 flex flex-wrap gap-1.5 text-xs" aria-label="Lompat ke kelompok" data-guide-jump data-guide-when-noquery @if ($search !== '') hidden @endif>
                        @foreach ($this->groups as $group => $items)
                            <a href="#grup-{{ \Illuminate\Support\Str::slug($group) }}" class="rounded-full border border-stone-300 bg-white px-3 py-1 font-medium text-stone-700 hover:border-emerald-600 hover:bg-emerald-50">{{ $group }} <span class="text-stone-400">{{ count($items) }}</span></a>
                        @endforeach
                    </nav>
                @endif
                <p data-guide-when-results @if ($this->groups === [] && $search !== '') hidden @endif class="mb-3 text-sm text-stone-600">{{ $search !== '' ? 'Hasil pencarian “'.$search.'”' : 'Pilih menu untuk melihat langkah-langkahnya.' }}</p>
                @foreach ($this->groups as $group => $items)
                    <div data-guide-group>
                    <h2 id="grup-{{ \Illuminate\Support\Str::slug($group) }}" class="mt-4 mb-2 scroll-mt-4 text-xs font-semibold tracking-wider text-stone-500 uppercase first:mt-0">{{ $group }}</h2>
                    <div class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                        @foreach ($items as $key => $e)
                            @php $icon = $e['menu'] ? (\App\Support\Menu::ITEMS[$e['menu']]['icon'] ?? null) : 'M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z'; @endphp
                            <button type="button" wire:click="open('{{ $key }}')" wire:key="card-{{ $key }}" data-guide-card="{{ $key }}" data-hay="{{ $hay($key, $e) }}"
                                    @class(['flex flex-col gap-2 rounded-2xl border p-4 text-left transition hover:shadow-sm focus-visible:ring-2 focus-visible:ring-emerald-600',
                                        'border-stone-200 bg-white hover:border-emerald-400' => $this->allowed($key), 'border-dashed border-stone-300 bg-stone-50 hover:border-stone-400' => ! $this->allowed($key)])>
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
                                @if ($this->allowed($key))
                                    <span class="flex flex-wrap items-center gap-2 text-[11px] font-medium text-emerald-800">{{ count($e['steps']) }} langkah →
                                        @if ($via = $this->via($key))<span class="rounded bg-sky-50 px-1.5 py-0.5 font-semibold text-sky-800 ring-1 ring-sky-200" data-guide-card-via="{{ $key }}">lewat peran {{ collect($via)->map($roleLabel)->join(' / ') }}</span>@endif
                                    </span>
                                @else
                                    <span class="text-[11px] font-semibold text-stone-500" data-restricted-card="{{ $key }}">{!! $lock !!} Terbatas — {{ ($r = $this->requiredFor($key)) ? 'untuk peran '.$roleList($r) : 'belum diberikan ke peran mana pun' }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                    </div>
                @endforeach
            </section>
        @else
            @php
                $keys = $visible;
                $pos = array_search($currentKey, $keys, true);
                $prev = $pos > 0 ? $keys[$pos - 1] : null;
                $next = $pos !== false && $pos < count($keys) - 1 ? $keys[$pos + 1] : null;
                $name = fn ($k) => $this->entries[$k]['menu'] ? \App\Support\Menu::ITEMS[$this->entries[$k]['menu']]['label'] : 'Akun saya';
            @endphp
            <article wire:key="article-{{ $currentKey }}" x-init="$el.getBoundingClientRect().top < 0 && $el.scrollIntoView({ block: 'start' })"
                     class="min-w-0 scroll-mt-4 rounded-2xl border border-stone-200 bg-white p-5 sm:p-6" aria-labelledby="guide-title" data-guide="{{ $currentKey }}">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2 print:hidden">
                    <span class="flex items-center gap-3">
                        <button type="button" wire:click="closeGuide" class="text-xs font-medium text-stone-500 hover:text-stone-800" data-guide-back>← Semua panduan</button>
                        @if ($this->canPreview())
                            <a href="{{ route('panduan.sunting', ['panduan' => $currentKey]) }}" wire:navigate class="text-xs font-semibold text-emerald-800 hover:underline" data-guide-edit-link>Sunting panduan</a>
                        @endif
                    </span>
                    @if ($pos !== false)
                        <span class="text-[11px] text-stone-500 tabular-nums" data-guide-position>{{ \App\Support\GuideCatalog::groupFor($current['menu']) }} · panduan {{ $pos + 1 }} dari {{ count($keys) }}</span>
                    @endif
                </div>
                <p class="text-xs font-semibold tracking-wider text-emerald-700 uppercase">{{ $current['menu'] ? (\App\Support\Menu::ITEMS[$current['menu']]['label'] ?? '') : 'Akun saya' }}</p>
                @php
                    // penanda bagian yang disunting manual: rinci untuk pengelola, catatan umum untuk pembaca lain
                    $edited = $this->canPreview() ? $this->overrideInfo : [];
                    $editedBadge = fn (string $f) => isset($edited[$f])
                        ? '<span class="ml-2 inline-block rounded bg-sky-100 px-1.5 py-0.5 align-middle text-[10px] font-semibold text-sky-800" data-guide-edited="'.$f.'">Disunting'.($edited[$f]['by'] ? ' oleh '.e($edited[$f]['by']) : '').($edited[$f]['at'] ? ' · '.e($edited[$f]['at']) : '').'</span>'
                        : '';
                @endphp
                <h2 id="guide-title" class="mt-1 text-xl font-semibold text-stone-900">{{ $current['title'] }}{!! $editedBadge('title') !!}</h2>
                @if (! $restricted && ($via = $this->via($currentKey)))
                    <p class="mt-1 text-xs text-sky-800" data-guide-article-via>Menu ini terbuka untuk Anda lewat peran <strong>{{ collect($via)->map($roleLabel)->join(', ', ' dan ') }}</strong>.</p>
                @endif
                <p class="mt-2 max-w-prose text-sm leading-relaxed text-stone-700" data-guide-summary>{{ $current['summary'] }}{!! $editedBadge('summary') !!}</p>
                @if (! $restricted && ($current['stale'] ?? false))
                    <p class="mt-3 max-w-prose rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200 print:hidden" role="note" data-guide-stale-note>
                        Panduan ini sedang ditinjau ulang karena ada perubahan fitur — beberapa langkah mungkin belum sesuai tampilan terbaru.
                        @if ($this->canPreview())
                            <a href="{{ route('panduan.pembaruan', ['tab' => 'usang']) }}" wire:navigate class="font-semibold underline">Tinjau di panel pembaruan</a>
                        @endif
                    </p>
                @endif

                @if ($restricted)
                    <div class="mt-6 rounded-xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-stone-700" data-restricted-panel>
                        <p class="font-semibold text-stone-900">{!! $lock !!} Menu terbatas</p>
                        <p class="mt-1">Menu {{ \App\Support\Menu::ITEMS[$current['menu']]['label'] }} tidak termasuk {{ $whose }}, jadi langkah-langkahnya tidak ditampilkan.</p>
                        @if ($requiredRoles)
                            <p class="mt-1">Menu ini dimiliki peran <strong>{{ $roleList($requiredRoles) }}</strong>. Bila Anda memerlukannya, minta admin koperasi menambahkan peran tersebut ke akun Anda.</p>
                        @else
                            <p class="mt-1">Menu ini belum diberikan ke peran mana pun.</p>
                        @endif
                    </div>
                @else
                @php $total = count($current['steps']); @endphp
                <div wire:key="steps-{{ $currentKey }}" x-data="guideProgress(@js('panduan:'.auth()->id().':'.$currentKey), {{ $total }})" data-guide-progress>
                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        <h3 class="text-sm font-semibold text-stone-900">Langkah-langkah{!! $editedBadge('steps') !!}</h3>
                        <span class="text-xs text-stone-500 tabular-nums" aria-live="polite"><span x-text="done.length">0</span> dari {{ $total }} langkah</span>
                        <div class="h-1.5 min-w-24 flex-1 overflow-hidden rounded-full bg-stone-100 print:hidden" aria-hidden="true">
                            <div class="h-full rounded-full bg-emerald-600 transition-all" x-bind:style="`width: ${Math.round(done.length / {{ $total }} * 100)}%`"></div>
                        </div>
                        <button type="button" x-show="done.length" x-cloak x-on:click="reset()" class="text-xs font-medium text-stone-500 hover:text-stone-800 print:hidden">Ulangi</button>
                        <button type="button" onclick="window.print()" class="text-xs font-medium text-emerald-800 hover:underline print:hidden" data-guide-print>Cetak</button>
                    </div>
                <ol class="mt-3 space-y-3" data-guide-steps>
                    @foreach ($current['steps'] as $i => $step)
                        <li class="flex gap-3" x-bind:class="has({{ $i }}) && 'opacity-60'">
                            <button type="button" x-on:click="toggle({{ $i }})" x-bind:aria-pressed="has({{ $i }})" aria-label="Tandai langkah {{ $i + 1 }} selesai" data-guide-step-toggle="{{ $i }}"
                                    class="grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold text-white transition print:border print:border-stone-400 print:bg-white print:text-stone-900"
                                    x-bind:class="has({{ $i }}) ? 'bg-stone-400' : 'bg-emerald-700 hover:bg-emerald-800'">
                                <span x-show="! has({{ $i }})">{{ $i + 1 }}</span><span x-show="has({{ $i }})" x-cloak aria-hidden="true">✓</span>
                            </button>
                            <div class="min-w-0 pt-0.5 text-sm text-stone-800" x-bind:class="has({{ $i }}) && 'line-through decoration-stone-300'">
                                {{ $step['text'] }}
                                @if (($lock = $this->stepLock($step['route'] ?? null)) !== null)
                                    <span class="ml-1 rounded bg-stone-100 px-1.5 py-0.5 text-[11px] font-semibold whitespace-nowrap text-stone-600" data-guide-step-locked="{{ $i }}">🔒 {{ $lock ? 'khusus peran '.collect($lock)->map($roleLabel)->join(' / ') : 'tidak tersedia untuk peran Anda' }}</span>
                                @elseif ($step['route'] ?? null)
                                    <a href="{{ route($step['route']) }}" wire:navigate class="ml-1 font-medium whitespace-nowrap text-emerald-800 hover:underline">Buka →</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
                </div>

                @if ($current['tips'])
                    <div class="mt-6 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900" data-guide-tips>
                        <p class="font-semibold">Tips{!! $editedBadge('tips') !!}</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5">
                            @foreach ($current['tips'] as $tip)<li>{{ $tip }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                @php $related = collect($current['related'])->filter(fn ($l, $r) => $this->reach($r)); @endphp
                @if ($related->isNotEmpty())
                    <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-stone-100 pt-4 text-sm">
                        <span class="text-stone-500">Halaman terkait:</span>
                        @foreach ($related as $r => $label)
                            <a href="{{ route($r) }}" wire:navigate class="rounded-lg border border-stone-300 px-2.5 py-1 font-medium text-stone-700 hover:bg-stone-50">{{ $label }}</a>
                        @endforeach
                    </div>
                @endif
                @endif
                @if (! $restricted && ($current['overridden'] ?? []) && ! $this->canPreview())
                    <p class="mt-4 text-xs text-stone-500" data-guide-edited-note>Sebagian isi panduan ini disesuaikan oleh pengelola koperasi.</p>
                @elseif (! $restricted && $edited)
                    <p class="mt-4 text-xs text-sky-800" data-guide-edited-summary>{{ count($edited) }} bagian disunting manual — tetap dipakai walau panduan disusun ulang. <a href="{{ route('panduan.sunting', ['panduan' => $currentKey]) }}" wire:navigate class="font-semibold underline">Kelola suntingan</a></p>
                @endif
                <nav class="mt-6 flex justify-between gap-3 border-t border-stone-100 pt-4 text-sm print:hidden" aria-label="Panduan lain">
                    @if ($prev)<button type="button" wire:click="open('{{ $prev }}')" class="font-medium text-stone-600 hover:text-stone-900" data-guide-prev="{{ $prev }}">← {{ $name($prev) }}</button>@else<span></span>@endif
                    @if ($next)<button type="button" wire:click="open('{{ $next }}')" class="font-medium text-stone-600 hover:text-stone-900" data-guide-next="{{ $next }}">{{ $name($next) }} →</button>@endif
                </nav>
            </article>
        @endif
    </div>
</div>
