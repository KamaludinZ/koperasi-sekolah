{{-- Panel geser: form tambah / ubah anggota --}}
@if ($editing)
    @php
        $input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
        $isNew = $form->id === null;
    @endphp
    <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="member-form-title"
         x-data x-on:keydown.escape.window="$wire.cancel()">
        <div class="absolute inset-0 bg-stone-950/50" wire:click="cancel"></div>

        <form wire:submit="save" class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl"
              x-init="$nextTick(() => $el.querySelector('[autofocus]')?.focus())">
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <h2 id="member-form-title" class="font-semibold">{{ $isNew ? 'Tambah anggota' : 'Ubah data anggota' }}</h2>
                <button type="button" wire:click="cancel" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5">
                <fieldset>
                    <legend class="mb-1.5 text-sm font-medium">Jenis anggota</legend>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (\App\Livewire\Anggota\Index::TYPES as $value => $label)
                            <label class="flex cursor-pointer items-center justify-center rounded-lg border border-stone-300 px-3 py-2.5 text-sm font-medium text-stone-700 transition has-checked:border-emerald-600 has-checked:bg-emerald-50 has-checked:text-emerald-900">
                                <input type="radio" value="{{ $value }}" wire:model.live="form.type" class="sr-only">{{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Nama lengkap</span>
                    <input type="text" wire:model="form.name" autofocus placeholder="{{ $form->type === 'guru' ? 'mis. Bu Ani Rahmawati' : 'mis. Andi Pratama' }}" class="{{ $input }}">
                    @error('form.name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">Kode anggota</span>
                        <input type="text" wire:model="form.member_code" class="{{ $input }} font-mono uppercase">
                        @error('form.member_code') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        @if ($isNew) <span class="mt-1 block text-[11px] text-stone-500">Dibuat otomatis, boleh diubah.</span> @endif
                    </label>
                    @if ($form->type === 'siswa')
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium">Kelas</span>
                            <input type="text" wire:model="form.class" placeholder="mis. 7A" list="kelas-list" class="{{ $input }} uppercase">
                            <datalist id="kelas-list">
                                @foreach ($this->classes as $c)<option value="{{ $c }}">@endforeach
                            </datalist>
                            @error('form.class') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    @endif
                </div>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">No. HP <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="tel" inputmode="tel" wire:model="form.phone" placeholder="08xx-xxxx-xxxx" class="{{ $input }}">
                    @error('form.phone') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="flex items-start gap-3 rounded-xl border border-stone-200 p-4">
                    <input type="checkbox" wire:model="form.is_active" class="mt-0.5 size-4 accent-emerald-700">
                    <span>
                        <span class="block text-sm font-medium">Anggota aktif</span>
                        <span class="block text-xs text-stone-500">Nonaktifkan untuk anggota yang sudah lulus/pindah. Riwayat tabungan tetap tersimpan.</span>
                    </span>
                </label>
            </div>

            <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                <button type="button" wire:click="cancel" class="rounded-xl border border-stone-300 px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="flex-1 rounded-xl bg-emerald-700 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
                    {{ $isNew ? 'Simpan anggota' : 'Simpan perubahan' }}
                </button>
            </div>
        </form>
    </div>
@endif
