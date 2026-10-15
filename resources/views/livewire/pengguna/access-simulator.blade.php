@php
    $labels = \App\Models\Role::LABELS;
    $roleLabel = fn ($n) => $labels[$n] ?? ucfirst($n);
    $combo = fn (array $names) => collect($names)->map($roleLabel)->join(' + ');
    $selected = $result['roles'];
    $pages = collect($result['pages']);
    $openCount = $pages->where('allowed', true)->count();
    $shownPages = match ($pageFilter) { 'terbuka' => $pages->where('allowed', true), 'tertutup' => $pages->where('allowed', false), default => $pages };
    $homeLabel = collect(\App\Support\Menu::ITEMS)->firstWhere('route', $result['home'])['label'] ?? 'Beranda';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Kelola Pengguna</h1>
        <p class="text-xs text-stone-500">Simulasikan kombinasi peran: menu, halaman, dan hak aksi yang didapat akun bila memegang peran-peran itu sekaligus.</p>
        <x-pengguna.nav active="pengguna.akses" />
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
        {{-- Pilih kombinasi --}}
        <aside class="space-y-4 lg:sticky lg:top-0 lg:self-start">
            <section class="rounded-xl border border-stone-200 bg-white p-4" aria-labelledby="pilih-peran">
                <h2 id="pilih-peran" class="text-sm font-semibold">Peran tiruan</h2>
                <p class="mt-0.5 text-xs text-stone-500">Hanya simulasi — tidak mengubah akun mana pun.</p>
                <div class="mt-3 space-y-1.5">
                    @foreach ($allRoles as $name => $role)
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2 text-sm has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                            <input type="checkbox" wire:model.live="roles" value="{{ $name }}" class="size-4 rounded border-stone-300 text-emerald-700 focus:ring-emerald-600">
                            <span class="flex-1 font-medium">{{ $roleLabel($name) }}</span>
                            <span class="text-xs text-stone-500 tabular-nums">{{ count($role->permissions ?? []) }} menu</span>
                        </label>
                    @endforeach
                </div>
                @if ($selected === [])
                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900" role="status">Belum ada peran dicentang. Akun tanpa peran tidak bisa membuka menu apa pun dan ditolak saat masuk.</p>
                @endif
            </section>

            @if ($this->usedCombinations)
                <section class="rounded-xl border border-stone-200 bg-white p-4" aria-labelledby="kombinasi-dipakai">
                    <h2 id="kombinasi-dipakai" class="text-sm font-semibold">Kombinasi yang dipakai akun</h2>
                    <ul class="mt-2 space-y-1">
                        @foreach ($this->usedCombinations as $c)
                            <li>
                                <button type="button" wire:click='useCombination(@json($c['roles']))'
                                        @class(['flex w-full items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-left text-sm hover:bg-stone-50',
                                                'bg-emerald-50 font-semibold text-emerald-900' => $c['roles'] === $selected])>
                                    <span>{{ $combo($c['roles']) }}</span>
                                    <span class="shrink-0 text-xs text-stone-500 tabular-nums">{{ $c['count'] }} akun</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="rounded-xl border border-stone-200 bg-white p-4">
                <label for="muat-akun" class="text-sm font-semibold">Muat dari akun</label>
                <select id="muat-akun" x-data x-on:change="$wire.loadUser(Number($event.target.value)); $event.target.value = ''"
                        class="mt-2 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    <option value="">Pilih akun petugas…</option>
                    @foreach ($this->accounts as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->name }} — {{ $combo($acc->roleNames()) ?: 'tanpa peran' }}{{ $acc->is_active ? '' : ' (nonaktif)' }}</option>
                    @endforeach
                </select>
            </section>
        </aside>

        <div class="min-w-0 space-y-5" wire:loading.class="opacity-60" wire:target="roles,useCombination,loadUser">
            {{-- Ringkasan --}}
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Ringkasan simulasi">
                <div class="rounded-xl border border-stone-200 bg-white p-4">
                    <p class="text-xs text-stone-500">Kombinasi</p>
                    <p class="mt-1 font-semibold" data-combo>{{ $selected ? $combo($selected) : '—' }}</p>
                    @if ($result['primary'])
                        <p class="mt-0.5 text-xs text-stone-500">Peran utama: {{ $roleLabel($result['primary']) }}</p>
                    @endif
                </div>
                <div class="rounded-xl border border-stone-200 bg-white p-4">
                    <p class="text-xs text-stone-500">Menu gabungan</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ count($result['permissions']) }} <span class="text-sm font-normal text-stone-500">dari {{ count(\App\Support\Menu::ITEMS) }}</span></p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white p-4">
                    <p class="text-xs text-stone-500">Halaman terbuka</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $openCount }} <span class="text-sm font-normal text-stone-500">dari {{ $pages->count() }}</span></p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white p-4">
                    <p class="text-xs text-stone-500">Halaman awal setelah masuk</p>
                    <p class="mt-1 font-semibold">{{ $selected ? $homeLabel : '— (ditolak masuk)' }}</p>
                </div>
            </section>

            <div class="grid gap-5 md:grid-cols-[13rem_minmax(0,1fr)]">
                {{-- Pratinjau menu samping --}}
                <section aria-labelledby="pratinjau-menu">
                    <h2 id="pratinjau-menu" class="mb-2 text-sm font-semibold">Menu samping yang tampil</h2>
                    <div class="relative isolate overflow-hidden rounded-xl bg-emerald-950 p-3 text-emerald-50" data-menu-preview>
                        <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.07]" aria-hidden="true"></div>
                        @forelse ($result['menu'] as $group)
                            <p class="ornament-divider mx-1 mt-2 mb-1 text-[10px] font-semibold tracking-[0.18em] text-amber-400/80 uppercase first:mt-0">{{ $group['label'] }}</p>
                            <ul class="space-y-0.5">
                                @foreach ($group['items'] as $key => $item)
                                    <li @class(['flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm',
                                                'bg-emerald-800 text-white' => $item['route'] === $result['home'],
                                                'text-emerald-200' => $item['route'] !== $result['home']])>
                                        <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $item['icon'] }}"/></svg>
                                        <span class="flex-1">{{ $item['label'] }}</span>
                                        @if (count($result['sources'][$key] ?? []) > 0 && count($selected) > 1)
                                            <span class="text-[10px] text-emerald-300/80">{{ collect($result['sources'][$key])->map(fn ($r) => mb_substr($roleLabel($r), 0, 1))->join('·') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @empty
                            <p class="px-1 py-6 text-center text-sm text-emerald-200/70">Tidak ada menu.</p>
                        @endforelse
                    </div>
                    @if (count($selected) > 1)
                        <p class="mt-1.5 text-[11px] text-stone-500">Huruf di kanan = peran asal menu ({{ collect($selected)->map(fn ($r) => mb_substr($roleLabel($r), 0, 1).' '.$roleLabel($r))->join(', ') }}).</p>
                    @endif
                </section>

                {{-- Matriks menu × peran --}}
                <section class="min-w-0" aria-labelledby="matriks">
                    <h2 id="matriks" class="mb-2 text-sm font-semibold">Gabungan izin per menu</h2>
                    <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white">
                        <table class="w-full min-w-[24rem] text-sm">
                            <thead class="bg-stone-50 text-xs text-stone-500">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium">Menu</th>
                                    @foreach ($allRoles as $name => $role)
                                        <th @class(['px-3 py-2 text-center font-medium', 'text-emerald-800' => in_array($name, $selected, true), 'opacity-50' => ! in_array($name, $selected, true)])>{{ $roleLabel($name) }}</th>
                                    @endforeach
                                    <th class="bg-emerald-50 px-3 py-2 text-center font-semibold text-emerald-900">Gabungan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach (\App\Support\Menu::ITEMS as $key => $item)
                                    @php $has = in_array($key, $result['permissions'], true); @endphp
                                    <tr wire:key="m-{{ $key }}" data-menu-row="{{ $key }}">
                                        <td class="px-4 py-2"><span @class(['font-medium', 'text-stone-400' => ! $has])>{{ $item['label'] }}</span></td>
                                        @foreach ($allRoles as $name => $role)
                                            <td @class(['px-3 py-2 text-center', 'opacity-40' => ! in_array($name, $selected, true)])>
                                                @if ($role->allows($key))
                                                    <span class="text-emerald-700" aria-label="ya">✓</span>
                                                @else
                                                    <span class="text-stone-300" aria-label="tidak">–</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="bg-emerald-50/50 px-3 py-2 text-center">
                                            @if ($has)
                                                <span class="font-semibold text-emerald-800" title="Dari {{ collect($result['sources'][$key])->map($roleLabel)->join(', ') }}">✓</span>
                                            @else
                                                <span class="text-stone-300">–</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {{-- Hak aksi --}}
            <section aria-labelledby="hak-aksi">
                <h2 id="hak-aksi" class="mb-2 text-sm font-semibold">Hak aksi</h2>
                <ul class="grid gap-2 sm:grid-cols-2">
                    @foreach ($result['abilities'] as $ability => $a)
                        <li wire:key="ab-{{ $ability }}" data-ability="{{ $ability }}" @class(['flex items-start gap-2.5 rounded-lg border px-3 py-2 text-sm',
                                    'border-emerald-200 bg-white' => $a['allowed'], 'border-stone-200 bg-stone-50 text-stone-500' => ! $a['allowed']])>
                            <span @class(['mt-0.5 grid size-4 shrink-0 place-items-center rounded-full text-[10px] font-bold', 'bg-emerald-600 text-white' => $a['allowed'], 'bg-stone-200 text-stone-500' => ! $a['allowed']]) aria-hidden="true">{{ $a['allowed'] ? '✓' : '×' }}</span>
                            <span class="flex-1">{{ $a['label'] }}<span class="sr-only">: {{ $a['allowed'] ? 'boleh' : 'tidak' }}</span></span>
                            @if ($a['combined'])
                                <span class="shrink-0 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900" title="Tak satu pun peran tunggalnya memberi hak ini">karena gabungan</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Halaman --}}
            <section aria-labelledby="halaman">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h2 id="halaman" class="text-sm font-semibold">Halaman petugas</h2>
                    <div class="flex gap-1 rounded-lg bg-stone-200/60 p-0.5 text-xs" role="group" aria-label="Saring halaman">
                        @foreach (['semua' => 'Semua', 'terbuka' => 'Terbuka', 'tertutup' => 'Tertutup (403)'] as $v => $l)
                            <button type="button" wire:click="$set('pageFilter', '{{ $v }}')" aria-pressed="{{ $pageFilter === $v ? 'true' : 'false' }}"
                                    @class(['rounded-md px-2.5 py-1 font-medium', 'bg-white shadow-sm' => $pageFilter === $v, 'text-stone-600' => $pageFilter !== $v])>{{ $l }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white">
                    <table class="w-full min-w-[36rem] text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500"><tr><th class="px-4 py-2 font-medium">Halaman</th><th class="px-3 py-2 font-medium">Menu</th><th class="px-3 py-2 font-medium">Akses</th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($shownPages as $p)
                                <tr wire:key="pg-{{ $p['name'] }}" data-page="{{ $p['name'] }}" data-allowed="{{ $p['allowed'] ? '1' : '0' }}">
                                    <td class="px-4 py-2"><span class="font-medium">{{ $p['title'] }}</span><span class="block font-mono text-[11px] text-stone-500">{{ $p['uri'] }}</span></td>
                                    <td class="px-3 py-2 text-stone-600">{{ \App\Support\Menu::ITEMS[$p['menu']]['label'] }}</td>
                                    <td class="px-3 py-2">
                                        @if ($p['allowed'])
                                            <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-semibold text-emerald-800">Terbuka</span>
                                        @else
                                            <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs font-semibold text-red-700">403</span>
                                            <span class="ml-1 text-xs text-stone-500">{{ $p['reason'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-8 text-center text-stone-400">Tidak ada halaman.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>
