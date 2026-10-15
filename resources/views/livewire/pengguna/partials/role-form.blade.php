{{-- Form kotak centang "Ubah peran": hanya peran akun (minimal satu), dengan pratinjau ditambah/dicabut & menu berubah --}}
@if ($roleUserId)
    @php
        $d = $this->roleDiff;
        $labels = \App\Models\Role::LABELS;
        $menuLabel = fn (array $keys) => collect(\App\Support\Menu::ITEMS)->filter(fn ($i, $k) => in_array($k, $keys, true))->pluck('label')->join(', ');
        $hints = ['admin' => 'Semua menu, termasuk kelola pengguna.', 'kasir' => 'Layar kasir, transaksi, barang, anggota.', 'pengurus' => 'Laporan, transaksi, barang, anggota.'];
    @endphp
    <div class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="role-form-title" x-data x-on:keydown.escape.window="$wire.cancelRoles()">
        <div class="absolute inset-0 bg-stone-950/50" wire:click="cancelRoles"></div>
        <form wire:submit="saveRoles" class="relative w-full max-w-md space-y-4 rounded-2xl bg-white p-5 shadow-xl" data-role-form>
            <div>
                <h2 id="role-form-title" class="font-semibold">Ubah peran · {{ $d['user']?->name }}</h2>
                <p class="text-xs text-stone-500">Centang satu atau beberapa peran. Hak menu akun = gabungan semua peran yang dicentang.</p>
            </div>
            <fieldset class="space-y-2">
                <legend class="sr-only">Peran</legend>
                @foreach ($labels as $value => $label)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 px-3 py-2.5 has-checked:border-emerald-600 has-checked:bg-emerald-50" data-role-choice="{{ $value }}">
                        <input type="checkbox" value="{{ $value }}" wire:model.live="roleChoice" class="mt-0.5 size-4 accent-emerald-700">
                        <span><span class="block text-sm font-medium">{{ $label }}
                            @if (in_array($value, $d['added'], true))<span class="ml-1 rounded bg-emerald-100 px-1.5 text-[11px] text-emerald-800">ditambah</span>@endif
                            @if (in_array($value, $d['removed'], true))<span class="ml-1 rounded bg-red-100 px-1.5 text-[11px] text-red-800">dicabut</span>@endif</span>
                            <span class="block text-xs text-stone-500">{{ $hints[$value] }}</span></span>
                    </label>
                @endforeach
            </fieldset>
            @error('roleChoice') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @error('roleChoice.*') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

            @if ($roleChoice === [])
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900" data-role-choice-empty>Pilih minimal satu peran.</p>
            @elseif ($d['added'] || $d['removed'])
                <div class="space-y-1 rounded-lg bg-stone-50 px-3 py-2 text-xs" data-role-diff>
                    @if ($d['added'])<p class="text-emerald-800">+ {{ collect($d['added'])->map(fn ($n) => $labels[$n])->join(', ') }}</p>@endif
                    @if ($d['removed'])<p class="text-red-800">− {{ collect($d['removed'])->map(fn ($n) => $labels[$n])->join(', ') }}</p>@endif
                    @if ($d['menus_added'])<p class="text-stone-600">Menu bertambah: {{ $menuLabel($d['menus_added']) }}</p>@endif
                    @if ($d['menus_removed'])<p class="text-stone-600">Menu hilang: {{ $menuLabel($d['menus_removed']) }}</p>@endif
                </div>
            @else
                <p class="text-xs text-stone-500" data-role-diff-none>Belum ada perubahan.</p>
            @endif

            <div class="flex gap-2">
                <button type="button" wire:click="cancelRoles" class="rounded-xl border border-stone-300 px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="saveRoles" @disabled($roleChoice === [])
                        class="flex-1 rounded-xl bg-emerald-700 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">Simpan peran</button>
            </div>
        </form>
    </div>
@endif
