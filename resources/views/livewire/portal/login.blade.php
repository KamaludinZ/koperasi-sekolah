@php
    $profile = \App\Support\MockData::profile();
    $input = 'w-full rounded-xl border bg-white px-3.5 py-2.5 text-[15px] outline-none transition placeholder:text-stone-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
    $error = fn (string $message) => '<p class="mt-1.5 flex items-center gap-1.5 text-sm text-red-600"><svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>'.e($message).'</p>';
@endphp
<div class="grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
    {{-- Panel identitas --}}
    <aside class="relative isolate flex flex-col justify-between overflow-hidden bg-emerald-950 px-6 py-8 text-emerald-50 sm:px-10 lg:py-12">
        <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.12]" aria-hidden="true"></div>
        <div class="absolute -right-24 -bottom-24 -z-10 size-80 rounded-full bg-emerald-800/40 blur-3xl" aria-hidden="true"></div>

        <a href="{{ route('beranda') }}" class="w-fit rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-amber-300">
            <x-brand.logo />
        </a>

        <div class="my-10 max-lg:hidden">
            <p class="text-sm font-semibold tracking-[0.18em] text-amber-300 uppercase">Portal anggota</p>
            <h2 class="mt-4 font-display text-4xl leading-tight font-bold text-white">Koperasi di genggaman<br>siswa & guru.</h2>
            <p class="mt-4 max-w-sm text-sm leading-relaxed text-emerald-200">{{ $profile['motto'] }}</p>
            <ul class="mt-8 space-y-3 text-sm text-emerald-100">
                @foreach (['Cek saldo tabungan & simpanan kapan saja', 'Pantau pinjaman dan jadwal angsuran', 'Pesan barang dulu, ambil tanpa antre', 'Riwayat belanja tersimpan rapi'] as $point)
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
    <div class="flex items-center justify-center bg-stone-50 px-5 py-10 sm:px-10">
        <div class="w-full max-w-md">
            <p class="text-sm font-medium text-emerald-700">Assalamu'alaikum,</p>
            <h1 class="mt-1 font-display text-3xl font-bold text-emerald-950">Masuk portal anggota</h1>
            <p class="mt-1 text-sm text-stone-500">Lihat simpanan & tabungan, lalu belanja tanpa antre di kasir.</p>

            @if (session('status'))
                <p class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">{{ session('status') }}</p>
            @endif

            <form wire:submit="login" class="mt-7 space-y-5" novalidate>
                <div>
                    <label for="identifier" class="mb-1.5 block text-sm font-medium text-stone-700">Kode anggota atau email</label>
                    <input id="identifier" type="text" wire:model="identifier" autocomplete="username" autocapitalize="characters" autofocus placeholder="mis. S-2026-001"
                           aria-describedby="identifier-error"
                           @class([$input, 'border-red-400' => $errors->has('identifier'), 'border-stone-300' => ! $errors->has('identifier')])>
                    <div id="identifier-error" aria-live="polite">
                        @error('identifier') {!! $error($message) !!} @else <p class="mt-1.5 text-xs text-stone-500">Kode anggota tertera di kartu anggota atau buku tabungan.</p> @enderror
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
                    @error('password') {!! $error($message) !!} @enderror
                </div>

                <button type="submit" wire:loading.attr="disabled" wire:target="login"
                        class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-800 py-3 text-[15px] font-semibold text-white shadow-sm transition hover:bg-emerald-900 focus-visible:ring-4 focus-visible:ring-emerald-600/30 focus-visible:outline-none disabled:opacity-60">
                    <svg wire:loading wire:target="login" class="size-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    <span wire:loading.remove wire:target="login">Masuk</span>
                    <span wire:loading wire:target="login">Memeriksa…</span>
                </button>
            </form>

            <div class="mt-6 rounded-xl border border-stone-200 bg-white px-4 py-3.5 text-sm text-stone-600">
                <p class="font-semibold text-stone-800">Belum punya akses?</p>
                <p class="mt-1 leading-relaxed">Minta pengurus koperasi mengaktifkan akun portal Anda — kata sandi & PIN 6 angka diberikan saat itu. PIN dipakai untuk memverifikasi setiap transaksi. Lupa kata sandi atau PIN? Hubungi pengurus untuk mengaturnya ulang.</p>
            </div>

            <div class="mt-8 flex flex-wrap items-center justify-between gap-3 text-sm">
                <a href="{{ route('beranda') }}" class="flex items-center gap-1.5 font-medium text-emerald-800 hover:underline">
                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                    Kembali ke beranda
                </a>
                <a href="{{ route('login') }}" class="text-stone-500 hover:text-emerald-800 hover:underline">Masuk sebagai petugas</a>
            </div>
        </div>
    </div>
</div>
