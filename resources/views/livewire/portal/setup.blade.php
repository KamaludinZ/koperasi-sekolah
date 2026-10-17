@php
    $m = $this->member;
    $input = 'w-full rounded-xl border bg-white px-3.5 py-2.5 text-[15px] outline-none transition placeholder:text-stone-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
    $cls = fn (string $f) => $input.' '.($errors->has($f) ? 'border-red-400' : 'border-stone-300');
    $err = fn (string $f) => $errors->has($f) ? '<span class="mt-1 block text-sm text-red-600">'.e($errors->first($f)).'</span>' : '';
    $step = 0;
@endphp
<div class="mx-auto max-w-lg space-y-5">
    <section class="rounded-2xl bg-emerald-900 p-5 text-white">
        <p class="text-sm text-emerald-200">Assalamu'alaikum, {{ $m->name }}</p>
        <h1 class="mt-1 text-2xl font-semibold">Siapkan akun Anda</h1>
        <p class="mt-2 text-sm text-emerald-100">
            @if ($this->needPassword && $this->needPin)
                Sebelum memakai portal, buat kata sandi dan PIN transaksi milik Anda sendiri. Pengurus koperasi tidak akan mengetahui keduanya.
            @elseif ($this->needPassword)
                Kata sandi Anda baru diatur ulang pengurus. Buat kata sandi baru milik Anda sendiri.
            @else
                PIN transaksi Anda belum ada atau baru diatur ulang pengurus. Buat PIN baru milik Anda sendiri.
            @endif
        </p>
    </section>

    <form wire:submit="save" class="space-y-5" novalidate>
        @if ($this->needPassword)
            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="setup-pw">
                <div>
                    <h2 id="setup-pw" class="font-semibold">{{ ++$step }}. Kata sandi baru</h2>
                    <p class="text-sm text-stone-500">Dipakai untuk masuk portal bersama kode anggota <span class="font-mono">{{ $m->member_code }}</span>. Minimal 8 karakter, gabungan huruf dan angka.</p>
                </div>
                <label class="block"><span class="mb-1 block text-sm font-medium text-stone-700">Kata sandi baru</span>
                    <input type="password" wire:model="password" autocomplete="new-password" maxlength="100" autofocus class="{{ $cls('password') }}">{!! $err('password') !!}</label>
                <label class="block"><span class="mb-1 block text-sm font-medium text-stone-700">Ulangi kata sandi baru</span>
                    <input type="password" wire:model="password_confirmation" autocomplete="new-password" maxlength="100" class="{{ $cls('password_confirmation') }}"></label>
            </section>
        @endif

        @if ($this->needPin)
            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="setup-pin">
                <div>
                    <h2 id="setup-pin" class="font-semibold">{{ ++$step }}. PIN transaksi</h2>
                    <p class="text-sm text-stone-500">6 angka untuk memastikan setiap pembayaran dari saldo benar-benar Anda. Hindari angka sama atau berurutan (111111, 123456).</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block"><span class="mb-1 block text-sm font-medium text-stone-700">PIN baru</span>
                        <input type="password" wire:model="pin" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" @if (! $this->needPassword) autofocus @endif class="{{ $cls('pin') }} tracking-[0.4em]">{!! $err('pin') !!}</label>
                    <label class="block"><span class="mb-1 block text-sm font-medium text-stone-700">Ulangi PIN</span>
                        <input type="password" wire:model="pin_confirmation" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" class="{{ $cls('pin_confirmation') }} tracking-[0.4em]"></label>
                </div>
            </section>
        @endif

        <button type="submit" wire:loading.attr="disabled" wire:target="save"
                class="w-full rounded-xl bg-emerald-800 py-3 text-[15px] font-semibold text-white shadow-sm hover:bg-emerald-900 disabled:opacity-60">Simpan & mulai pakai portal</button>
    </form>

    <form method="POST" action="{{ route('portal.logout') }}" class="text-center">
        @csrf
        <button type="submit" class="text-sm text-stone-500 hover:text-emerald-800 hover:underline">Bukan Anda? Keluar</button>
    </form>
</div>
