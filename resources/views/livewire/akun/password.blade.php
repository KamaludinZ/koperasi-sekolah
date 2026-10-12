@php
    $input = 'w-full rounded-xl border bg-white px-3.5 py-2.5 pr-12 text-[15px] outline-none transition focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
    $field = fn ($name) => $errors->has($name) ? 'border-red-400' : 'border-stone-300';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <h1 class="text-lg font-semibold">Ganti Kata Sandi</h1>
        <p class="text-xs text-stone-500">
            @if ($user) Akun {{ $user['email'] }} · {{ \App\Support\MockData::ROLES[$user['role']] ?? $user['role'] }} @endif
            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">data tiruan</span>
        </p>
    </header>

    <div class="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,28rem)_minmax(0,22rem)]">
        <form wire:submit="save" class="space-y-5 rounded-2xl border border-stone-200 bg-white p-5 sm:p-6" novalidate
              x-data="{
                  show: false,
                  pw: @entangle('password'),
                  get score() { return [this.pw.length >= 8, /[A-Za-z]/.test(this.pw) && /\d/.test(this.pw), this.pw.length >= 12, /[^A-Za-z0-9]/.test(this.pw)].filter(Boolean).length },
              }">
            @if ($saved)
                <p class="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
                    <svg class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 13 4 4L19 7"/></svg>
                    Kata sandi berhasil diganti. Gunakan kata sandi baru saat masuk berikutnya.
                </p>
            @endif

            <div>
                <label for="current_password" class="mb-1.5 block text-sm font-medium text-stone-700">Kata sandi sekarang</label>
                <input id="current_password" x-bind:type="show ? 'text' : 'password'" type="password" wire:model="current_password" autocomplete="current-password" autofocus
                       class="{{ $input }} {{ $field('current_password') }}">
                @error('current_password') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="ornament-divider text-amber-500" aria-hidden="true"><svg class="size-3" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0l2 4.6L14.6 4 12 8l2.6 4-4.6-.6L8 16l-2-4.6-4.6.6L4 8 1.4 4 6 4.6z"/></svg></div>

            <div>
                <label for="password" class="mb-1.5 block text-sm font-medium text-stone-700">Kata sandi baru</label>
                <input id="password" x-bind:type="show ? 'text' : 'password'" type="password" x-model="pw" autocomplete="new-password"
                       aria-describedby="pw-strength" class="{{ $input }} {{ $field('password') }}">
                {{-- Indikator kekuatan (hanya petunjuk; aturan tetap dicek server) --}}
                <div id="pw-strength" class="mt-2">
                    <div class="grid grid-cols-4 gap-1" aria-hidden="true">
                        <template x-for="i in 4">
                            <span class="h-1.5 rounded-full transition"
                                  x-bind:class="i <= score ? ['bg-red-500', 'bg-amber-500', 'bg-emerald-500', 'bg-emerald-700'][score - 1] : 'bg-stone-200'"></span>
                        </template>
                    </div>
                    <p class="mt-1 text-xs text-stone-500" x-show="pw.length" x-cloak
                       x-text="'Kekuatan: ' + ['terlalu lemah', 'lemah', 'cukup', 'kuat', 'sangat kuat'][score]"></p>
                </div>
                @error('password') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-stone-700">Ulangi kata sandi baru</label>
                <input id="password_confirmation" x-bind:type="show ? 'text' : 'password'" type="password" wire:model="password_confirmation" autocomplete="new-password"
                       class="{{ $input }} border-stone-300">
            </div>

            <label class="flex w-fit items-center gap-2 text-sm text-stone-600">
                <input type="checkbox" x-model="show" class="size-4 accent-emerald-700"> Tampilkan kata sandi
            </label>

            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                    class="w-full rounded-xl bg-emerald-800 py-3 text-[15px] font-semibold text-white hover:bg-emerald-900 disabled:opacity-60">
                <span wire:loading.remove wire:target="save">Simpan kata sandi baru</span>
                <span wire:loading wire:target="save">Menyimpan…</span>
            </button>
        </form>

        <aside class="relative isolate h-fit overflow-hidden rounded-2xl bg-emerald-950 p-5 text-emerald-50">
            <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.08]" aria-hidden="true"></div>
            <h2 class="font-display text-xl font-bold text-white">Kata sandi yang amanah</h2>
            <ul class="mt-3 space-y-2.5 text-sm text-emerald-100">
                @foreach (['Minimal 8 karakter, gabungan huruf dan angka.', 'Jangan memakai nama, tanggal lahir, atau NIS.', 'Jangan berikan kata sandi kepada siapa pun, termasuk sesama petugas.', 'Keluar dari aplikasi bila komputer kasir dipakai bergantian.'] as $tip)
                    <li class="flex gap-2.5"><span class="text-amber-300" aria-hidden="true">✦</span>{{ $tip }}</li>
                @endforeach
            </ul>
        </aside>
    </div>
</div>
