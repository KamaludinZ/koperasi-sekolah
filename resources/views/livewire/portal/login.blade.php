@php
    $profile = \App\Support\MockData::profile();
    $input = 'w-full rounded-xl border bg-white px-3.5 py-2.5 text-[15px] outline-none transition placeholder:text-stone-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
@endphp
<div class="flex min-h-dvh flex-col items-center justify-center bg-emerald-950 px-4 py-10">
    <a href="{{ route('beranda') }}" class="mb-6 text-emerald-50"><x-brand.logo /></a>
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
        <h1 class="text-xl font-semibold">Masuk portal anggota</h1>
        <p class="mt-1 text-sm text-stone-500">Lihat simpanan & tabungan, lalu belanja tanpa antre di kasir.</p>
        <form wire:submit="login" class="mt-5 space-y-4" novalidate>
            <label class="block">
                <span class="mb-1 block text-sm font-medium">Kode anggota atau email</span>
                <input type="text" wire:model="identifier" autocomplete="username" autocapitalize="none" autofocus placeholder="mis. S-2026-001"
                       @class([$input, 'border-red-400' => $errors->has('identifier'), 'border-stone-300' => ! $errors->has('identifier')])>
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium">Kata sandi</span>
                <input type="password" wire:model="password" autocomplete="current-password"
                       @class([$input, 'border-red-400' => $errors->has('password'), 'border-stone-300' => ! $errors->has('password')])>
                @error('password') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            @error('identifier') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p> @enderror
            <button type="submit" wire:loading.attr="disabled" class="w-full rounded-xl bg-emerald-700 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
                <span wire:loading.remove wire:target="login">Masuk</span><span wire:loading wire:target="login">Memeriksa…</span>
            </button>
        </form>
        <p class="mt-4 text-xs leading-relaxed text-stone-500">Belum punya akses? Minta pengurus koperasi mengaktifkan akun portal Anda. PIN 6 angka dipakai untuk memverifikasi setiap transaksi. Lupa kata sandi atau PIN? Hubungi pengurus untuk mengaturnya ulang.</p>
    </div>
    <p class="mt-6 text-xs text-emerald-300/80">{{ $profile['name'] }} · <a href="{{ route('login') }}" class="hover:underline">Masuk petugas</a></p>
</div>
