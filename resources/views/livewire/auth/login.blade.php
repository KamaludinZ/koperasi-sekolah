@php
    $profile = \App\Support\MockData::profile();
    $input = 'w-full rounded-xl border bg-white px-3.5 py-2.5 text-[15px] outline-none transition placeholder:text-stone-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
@endphp
<div class="grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]" data-clear-offline>
    {{-- Panel identitas --}}
    <aside class="relative isolate flex flex-col justify-between overflow-hidden bg-emerald-950 px-6 py-8 text-emerald-50 sm:px-10 lg:py-12">
        <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.12]" aria-hidden="true"></div>
        <div class="absolute -right-24 -bottom-24 -z-10 size-80 rounded-full bg-emerald-800/40 blur-3xl" aria-hidden="true"></div>

        <a href="{{ route('beranda') }}" class="w-fit rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-amber-300">
            <x-brand.logo />
        </a>

        <div class="my-10 max-lg:hidden">
            <p class="font-display text-3xl leading-relaxed text-amber-300" lang="ar" dir="rtl">بِسْمِ اللّٰهِ الرَّحْمٰنِ الرَّحِيْمِ</p>
            <h2 class="mt-6 font-display text-4xl leading-tight font-bold text-white">Melayani warga madrasah<br>dengan amanah.</h2>
            <p class="mt-4 max-w-sm text-sm leading-relaxed text-emerald-200">{{ $profile['motto'] }}</p>
            <ul class="mt-8 space-y-3 text-sm text-emerald-100">
                @foreach (['Kasir cepat dengan struk otomatis', 'Stok barang & tabungan anggota tercatat rapi', 'Rekap penjualan siap dilaporkan'] as $point)
                    <li class="flex items-center gap-3">
                        <svg class="size-4 shrink-0 text-amber-300" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0l2 4.6L14.6 4 12 8l2.6 4-4.6-.6L8 16l-2-4.6-4.6.6L4 8 1.4 4 6 4.6z"/></svg>
                        {{ $point }}
                    </li>
                @endforeach
            </ul>
        </div>

        <p class="text-xs text-emerald-300/80 max-lg:hidden">{{ $profile['name'] }} · {{ $profile['address'] }}</p>
    </aside>

    {{-- Form masuk --}}
    <main class="flex items-center justify-center px-5 py-10 sm:px-10">
        <div class="w-full max-w-md">
            <p class="text-sm font-medium text-emerald-700">Assalamu'alaikum,</p>
            <h1 class="mt-1 font-display text-3xl font-bold text-emerald-950">Masuk petugas</h1>
            <p class="mt-1 text-sm text-stone-500">Gunakan akun yang diberikan admin koperasi.</p>

            @if (session('status'))
                <p class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">{{ session('status') }}</p>
            @endif

            @if ($lock)
                @if ($lock['manual'])
                    <div class="mt-5 flex gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3.5 text-sm text-red-900" role="alert">
                        <svg class="mt-0.5 size-5 shrink-0 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
                        <div>
                            <p class="font-semibold">Akun dikunci karena terlalu banyak percobaan masuk</p>
                            <p class="mt-0.5 text-red-800">Demi keamanan, akun ini hanya bisa dibuka oleh admin koperasi. Hubungi admin, lalu coba lagi.</p>
                        </div>
                    </div>
                @else
                    <div wire:key="lock-{{ $lock['seconds'] }}" class="mt-5 flex gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3.5 text-sm text-amber-950" role="alert"
                         x-data="{ s: {{ (int) $lock['seconds'] }}, t: null,
                                   init() { this.t = setInterval(() => { if (--this.s <= 0) { clearInterval(this.t); $wire.lockExpired() } }, 1000) },
                                   destroy() { clearInterval(this.t) } }">
                        <svg class="mt-0.5 size-5 shrink-0 text-amber-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M9 2h6"/></svg>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">Terlalu banyak percobaan masuk</p>
                            <p class="mt-0.5 text-amber-900">Untuk keamanan, coba lagi dalam
                                <span class="font-semibold tabular-nums" x-text="s >= 60 ? Math.floor(s / 60) + ' menit ' + String(s % 60).padStart(2, '0') + ' detik' : s + ' detik'">{{ $lock['seconds'] }} detik</span>.
                            </p>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-amber-200" aria-hidden="true">
                                <div class="h-full rounded-full bg-amber-500 transition-[width] duration-1000 ease-linear" x-bind:style="'width:' + (s / {{ max(1, (int) $lock['seconds']) }} * 100) + '%'" style="width: 100%"></div>
                            </div>
                        </div>
                    </div>
                @endif
            @endif

            <form wire:submit="login" class="mt-7 space-y-5" novalidate>
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-stone-700">Email</label>
                    <input id="email" type="email" wire:model="email" autocomplete="username" autofocus inputmode="email"
                           placeholder="nama@madrasah.sch.id" aria-describedby="email-error"
                           @class([$input, 'border-red-400' => $errors->has('email'), 'border-stone-300' => ! $errors->has('email')])>
                    <div id="email-error" aria-live="polite">
                        @error('email')
                            <p class="mt-1.5 flex items-center gap-1.5 text-sm text-red-600">
                                <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>
                                {{ $message }}
                            </p>
                        @enderror
                        @if (! $lock && $attemptsLeft !== null && $attemptsLeft <= \App\Livewire\Auth\Login::WARN_AT)
                            <p class="mt-1.5 text-sm text-amber-800">Sisa {{ $attemptsLeft }} kali percobaan sebelum masuk dikunci sementara.</p>
                        @endif
                    </div>
                </div>

                <div x-data="{ show: false }">
                    <label for="password" class="mb-1.5 block text-sm font-medium text-stone-700">Kata sandi</label>
                    <div class="relative">
                        <input id="password" x-bind:type="show ? 'text' : 'password'" type="password" wire:model="password" autocomplete="current-password"
                               @class([$input, 'pr-12', 'border-red-400' => $errors->has('password'), 'border-stone-300' => ! $errors->has('password')])>
                        <button type="button" x-on:click="show = !show" x-bind:aria-label="show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                                class="absolute inset-y-0 right-0 grid w-12 place-items-center rounded-r-xl text-stone-400 hover:text-stone-700 focus-visible:text-emerald-700 focus-visible:outline-none">
                            <svg x-show="!show" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg x-show="show" x-cloak class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10 10 0 0112 5c6.4 0 10 7 10 7a17 17 0 01-3.2 4M6.6 6.6A17 17 0 002 12s3.6 7 10 7a10 10 0 005.4-1.6M9.9 9.9a3 3 0 004.2 4.2"/></svg>
                        </button>
                    </div>
                    @error('password') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <label class="flex w-fit cursor-pointer items-center gap-2.5 text-sm text-stone-700">
                    <input type="checkbox" wire:model="remember" class="size-4 rounded accent-emerald-700">
                    Ingat saya di perangkat ini
                </label>

                <button type="submit" wire:loading.attr="disabled" wire:target="login" @disabled($lock)
                        class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-800 py-3 text-[15px] font-semibold text-white shadow-sm transition hover:bg-emerald-900 focus-visible:ring-4 focus-visible:ring-emerald-600/30 focus-visible:outline-none disabled:opacity-60">
                    <svg wire:loading wire:target="login" class="size-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    <span wire:loading.remove wire:target="login">Masuk</span>
                    <span wire:loading wire:target="login">Memeriksa…</span>
                </button>
            </form>

            <p class="mt-5 text-center text-sm text-stone-500">Lupa kata sandi? Hubungi admin koperasi untuk mengaturnya ulang.</p>

            @if ($demoUsers)
                <details class="group mt-8 rounded-xl border border-dashed border-amber-300 bg-amber-50/60 text-sm">
                    <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 font-medium text-amber-900">
                        Akun demo (tahap pengembangan)
                        <svg class="size-4 transition group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <ul class="divide-y divide-amber-200/70 border-t border-amber-200/70">
                        @foreach ($demoUsers as $u)
                            <li>
                                <button type="button" wire:click="$set('email', '{{ $u['email'] }}')"
                                        class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left hover:bg-amber-100/60">
                                    <span class="min-w-0">
                                        <span class="block font-medium text-stone-800">{{ $u['name'] }}</span>
                                        <span class="block truncate font-mono text-xs text-stone-500">{{ $u['email'] }}</span>
                                    </span>
                                    <span @class(['shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                                  'bg-stone-200 text-stone-600' => ! $u['is_active'],
                                                  'bg-emerald-100 text-emerald-800' => $u['is_active']])>{{ $u['is_active'] ? \App\Models\Role::LABELS[$u['role']] : 'Nonaktif' }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    <p class="border-t border-amber-200/70 px-4 py-2.5 text-xs text-amber-900/80">Klik akun untuk mengisi email. Kata sandi semua akun demo: <code class="font-mono">password</code>.</p>
                </details>
            @endif

            <a href="{{ route('beranda') }}" class="mt-8 flex w-fit items-center gap-1.5 text-sm font-medium text-emerald-800 hover:underline">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                Kembali ke beranda koperasi
            </a>
        </div>
    </main>
</div>
