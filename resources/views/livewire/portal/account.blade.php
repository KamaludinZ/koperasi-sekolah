@php
    $m = $this->member;
    $input = 'w-full rounded-xl border bg-white px-3.5 py-2.5 text-[15px] outline-none transition placeholder:text-stone-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15 disabled:bg-stone-100';
    $cls = fn (string $f) => $input.' '.($errors->has($f) ? 'border-red-400' : 'border-stone-300');
    $ro = $this->readOnly;
    $lock = $this->lock;
    $field = function (string $name, string $label, string $type = 'password', array $attrs = []) use ($cls, $ro, $errors) {
        $extra = collect($attrs)->map(fn ($v, $k) => $k.'="'.e($v).'"')->implode(' ');
        $err = $errors->first($name);

        return '<label class="block"><span class="mb-1 block text-sm font-medium text-stone-700">'.e($label).'</span>'
            .'<input type="'.$type.'" wire:model="'.$name.'" '.$extra.' '.($ro ? 'disabled' : '').' class="'.$cls($name).'"'.($err ? ' aria-invalid="true"' : '').'>'
            .($err ? '<span class="mt-1 block text-sm text-red-600">'.e($err).'</span>' : '').'</label>';
    };
@endphp
<div class="space-y-5">
    <h1 class="text-xl font-semibold">Akun saya</h1>

    <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="acc-id">
        <h2 id="acc-id" class="sr-only">Data akun</h2>
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-stone-500">Nama</dt><dd class="font-medium">{{ $m->name }}</dd></div>
            <div><dt class="text-stone-500">Kode anggota (untuk masuk)</dt><dd class="font-mono font-medium">{{ $m->member_code }}</dd></div>
            <div><dt class="text-stone-500">Email</dt><dd class="font-medium">{{ $m->email ?: '—' }}</dd></div>
            <div><dt class="text-stone-500">Terakhir masuk</dt><dd class="font-medium">{{ $m->portal_last_login_at?->translatedFormat('d F Y H:i') ?? '—' }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-stone-500">Nama, kelas, dan email diubah oleh pengurus koperasi.</p>
    </section>

    @if ($ro)
        <p class="rounded-xl bg-stone-100 px-4 py-3 text-sm text-stone-600" data-read-only>Mode impersonasi: kata sandi & PIN anggota hanya bisa diganti oleh anggota sendiri.</p>
    @elseif ($lock['locked'])
        <p class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="alert">Terlalu banyak kata sandi salah. Penggantian kata sandi & PIN dikunci, coba lagi dalam {{ $lock['minutes'] }} menit.</p>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <form wire:submit="changePassword" class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" novalidate aria-labelledby="acc-pw">
            <div>
                <h2 id="acc-pw" class="font-semibold">Ganti kata sandi</h2>
                @if ($m->portal_password_changed_at)
                    <p class="text-sm text-stone-500">Terakhir diganti {{ $m->portal_password_changed_at->translatedFormat('d F Y') }}.</p>
                @endif
            </div>
            {!! $field('current_password', 'Kata sandi sekarang', 'password', ['autocomplete' => 'current-password']) !!}
            {!! $field('password', 'Kata sandi baru', 'password', ['autocomplete' => 'new-password', 'maxlength' => '100']) !!}
            {!! $field('password_confirmation', 'Ulangi kata sandi baru', 'password', ['autocomplete' => 'new-password', 'maxlength' => '100']) !!}
            <p class="text-xs text-stone-500">Minimal 8 karakter, gabungan huruf dan angka. Perangkat lain yang masih masuk akan diminta masuk lagi.</p>
            <button type="submit" @disabled($ro) wire:loading.attr="disabled" wire:target="changePassword"
                    class="w-full rounded-xl bg-emerald-800 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-60">Simpan kata sandi baru</button>
        </form>

        <form wire:submit="changePin" class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" novalidate aria-labelledby="acc-pin">
            <div>
                <h2 id="acc-pin" class="font-semibold">Ganti PIN transaksi</h2>
                <p class="text-sm text-stone-500">PIN 6 angka dipakai untuk memastikan setiap pembayaran dari saldo adalah Anda.@if ($m->portal_pin_changed_at) Terakhir diganti {{ $m->portal_pin_changed_at->translatedFormat('d F Y') }}.@endif</p>
            </div>
            {!! $field('pin', 'PIN baru', 'password', ['inputmode' => 'numeric', 'maxlength' => '6', 'pattern' => '[0-9]*', 'autocomplete' => 'off']) !!}
            {!! $field('pin_confirmation', 'Ulangi PIN baru', 'password', ['inputmode' => 'numeric', 'maxlength' => '6', 'pattern' => '[0-9]*', 'autocomplete' => 'off']) !!}
            {!! $field('pin_password', 'Kata sandi portal (konfirmasi)', 'password', ['autocomplete' => 'current-password']) !!}
            <p class="text-xs text-stone-500">Hindari angka sama atau berurutan (111111, 123456). Lupa PIN? Cukup buat PIN baru di sini dengan kata sandi Anda.</p>
            <button type="submit" @disabled($ro) wire:loading.attr="disabled" wire:target="changePin"
                    class="w-full rounded-xl bg-emerald-800 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-60">Simpan PIN baru</button>
        </form>
    </div>

    <p class="text-sm text-stone-500">Lupa kata sandi? Minta pengurus koperasi mengatur ulang — Anda mendapat kata sandi sementara lalu membuat yang baru saat masuk.</p>
</div>
