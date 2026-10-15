{{-- Panel geser: form tambah / ubah akun petugas --}}
@if ($editing)
    @php
        $input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
        $isNew = $form->id === null;
        $isMe = $form->id !== null && $form->id === $this->currentUserId();
        $hints = ['admin' => 'Semua menu, termasuk kelola pengguna.', 'kasir' => 'Layar kasir, transaksi, barang, anggota.', 'pengurus' => 'Laporan, transaksi, barang, anggota.'];
    @endphp
    <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="user-form-title"
         x-data x-on:keydown.escape.window="$wire.cancel()">
        <div class="absolute inset-0 bg-stone-950/50" wire:click="cancel"></div>

        <form wire:submit="save" class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl"
              x-init="$nextTick(() => $el.querySelector('[autofocus]')?.focus())">
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <h2 id="user-form-title" class="font-semibold">{{ $isNew ? 'Tambah pengguna' : 'Ubah akun pengguna' }}</h2>
                <button type="button" wire:click="cancel" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Nama</span>
                    <input type="text" wire:model="form.name" autofocus placeholder="mis. Bu Ani Rahmawati" class="{{ $input }}">
                    @error('form.name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Email untuk masuk</span>
                    <input type="email" wire:model="form.email" autocomplete="off" placeholder="nama@madrasah.sch.id" class="{{ $input }}">
                    @error('form.email') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <fieldset>
                    <legend class="mb-1.5 text-sm font-medium">Peran <span class="font-normal text-stone-500">(boleh lebih dari satu)</span></legend>
                    <div class="space-y-2">
                        @foreach (\App\Models\Role::LABELS as $value => $label)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 px-3 py-2.5 transition has-checked:border-emerald-600 has-checked:bg-emerald-50" data-role-option="{{ $value }}">
                                <input type="checkbox" value="{{ $value }}" wire:model.live="form.roles" class="mt-0.5 size-4 accent-emerald-700">
                                <span>
                                    <span class="block text-sm font-medium">{{ $label }}</span>
                                    <span class="block text-xs text-stone-500">{{ $hints[$value] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @if ($form->roles === [])
                        <p class="mt-1 text-xs text-amber-700" data-roles-empty>Pilih minimal satu peran — akun tanpa peran tidak bisa membuka apa pun.</p>
                    @endif
                    @error('form.roles') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    @error('form.roles.*') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    @php
                        $roleMenus = $this->roles->mapWithKeys(fn ($r) => [$r->name => $r->permissions ?? []]);
                        $union = collect($form->roles)->flatMap(fn ($r) => $roleMenus[$r] ?? [])->unique()->all();
                    @endphp
                    @if (count($form->roles) > 0)
                        <p class="mt-2 rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600" data-union-menus>
                            <span class="font-medium text-stone-800">Menu gabungan ({{ count($union) }}):</span>
                            {{ collect(\App\Support\Menu::ITEMS)->filter(fn ($i, $k) => in_array($k, $union, true))->pluck('label')->join(', ') ?: '—' }}
                        </p>
                    @endif
                    <details class="mt-2 rounded-lg border border-stone-200" open>
                        <summary class="cursor-pointer px-3 py-2 text-xs font-medium text-stone-600">Menu yang bisa dibuka tiap peran</summary>
                        <div class="overflow-x-auto px-3 pb-3">
                            <table class="w-full text-xs" data-permission-matrix>
                                <thead><tr class="text-left text-stone-500"><th class="py-1 font-medium">Menu</th>
                                    @foreach (\App\Models\Role::LABELS as $value => $label)
                                        <th @class(['px-2 py-1 text-center font-medium', 'rounded-t bg-emerald-50 text-emerald-900' => in_array($value, $form->roles, true)])>{{ $label }}</th>
                                    @endforeach
                                </tr></thead>
                                <tbody class="divide-y divide-stone-100">
                                    @foreach (\App\Support\Menu::ITEMS as $key => $item)
                                        <tr>
                                            <td class="py-1 pr-2 text-stone-700">{{ $item['label'] }}</td>
                                            @foreach (\App\Models\Role::LABELS as $value => $label)
                                                @php $has = in_array($key, $roleMenus[$value] ?? [], true); @endphp
                                                <td @class(['px-2 py-1 text-center', 'bg-emerald-50' => in_array($value, $form->roles, true)])>
                                                    <span @class(['font-bold', 'text-emerald-700' => $has, 'text-stone-300' => ! $has])>{{ $has ? '✓' : '–' }}</span><span class="sr-only">{{ $has ? 'boleh' : 'tidak' }}</span>
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <p class="mt-1 text-[11px] text-stone-500">Ubah isi menu per peran lewat panel Peran & hak menu.</p>
                        </div>
                    </details>
                </fieldset>

                <fieldset class="rounded-xl border border-stone-200 p-4" x-data="{ show: false }">
                    <legend class="px-1 text-sm font-medium">{{ $isNew ? 'Kata sandi awal' : 'Atur ulang kata sandi' }}</legend>
                    @unless ($isNew)<p class="mb-3 text-xs text-stone-500">Kosongkan bila tidak ingin mengubah. Bila diisi, akun ini dikeluarkan dari semua perangkat.</p>@endunless
                    <div class="space-y-3">
                        <label class="block">
                            <span class="mb-1 block text-xs text-stone-600">Kata sandi (min. 8 karakter, huruf & angka)</span>
                            <input x-bind:type="show ? 'text' : 'password'" type="password" wire:model="form.password" autocomplete="new-password" class="{{ $input }}">
                            @error('form.password') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs text-stone-600">Ulangi kata sandi</span>
                            <input x-bind:type="show ? 'text' : 'password'" type="password" wire:model="form.password_confirmation" autocomplete="new-password" class="{{ $input }}">
                        </label>
                        <label class="flex items-center gap-2 text-xs text-stone-600">
                            <input type="checkbox" x-model="show" class="size-3.5 accent-emerald-700"> Tampilkan kata sandi
                        </label>
                    </div>
                </fieldset>

                <label @class(['flex items-start gap-3 rounded-xl border border-stone-200 p-4', 'opacity-60' => $isMe])>
                    <input type="checkbox" wire:model="form.is_active" @disabled($isMe) class="mt-0.5 size-4 accent-emerald-700">
                    <span>
                        <span class="block text-sm font-medium">Akun aktif</span>
                        <span class="block text-xs text-stone-500">{{ $isMe ? 'Akun yang sedang Anda pakai selalu aktif.' : 'Akun nonaktif tidak bisa masuk, tetapi riwayat transaksinya tetap tersimpan.' }}</span>
                    </span>
                </label>
                @error('form.is_active') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
            </div>

            <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                <button type="button" wire:click="cancel" class="rounded-xl border border-stone-300 px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="flex-1 rounded-xl bg-emerald-700 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
                    {{ $isNew ? 'Buat akun' : 'Simpan perubahan' }}
                </button>
            </div>
        </form>
    </div>
@endif
