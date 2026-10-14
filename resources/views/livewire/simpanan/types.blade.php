@use('App\Models\SavingType')
@php
    $input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
    $kindTone = ['pokok' => 'bg-sky-100 text-sky-800', 'wajib' => 'bg-amber-100 text-amber-900', 'sukarela' => 'bg-emerald-100 text-emerald-800', 'lainnya' => 'bg-stone-100 text-stone-700'];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Simpanan Anggota</h1>
                <p class="text-xs text-stone-500">Jenis simpanan pokok, wajib, dan sukarela — terpisah dari tabungan belanja. Dikelola pengurus.</p>
            </div>
            @if ($this->canManage)
                <button type="button" wire:click="create" class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Tambah jenis</button>
            @endif
        </div>
        <x-simpanan.nav active="simpanan" />
    </header>

    <div class="px-4 py-5 sm:px-6">
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar jenis simpanan">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Jenis simpanan</th>
                            <th class="px-3 py-2 font-medium">Golongan</th>
                            <th class="px-3 py-2 text-right font-medium">Nominal setor</th>
                            <th class="px-3 py-2 font-medium">Periode</th>
                            <th class="px-3 py-2 font-medium">Penarikan</th>
                            <th class="px-4 py-2"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->types as $t)
                            <tr wire:key="st-{{ $t['id'] }}" @class(['hover:bg-stone-50', 'text-stone-400' => ! $t['is_active']])>
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ $t['name'] }} @unless ($t['is_active'])<span class="ml-1 rounded bg-stone-200 px-1.5 py-0.5 text-[11px] font-medium text-stone-600">Nonaktif</span>@endunless</p>
                                    <p class="font-mono text-[11px] text-stone-400">{{ $t['code'] }}@if ($t['description']) · <span class="font-sans">{{ $t['description'] }}</span>@endif</p>
                                </td>
                                <td class="px-3 py-3"><span class="rounded-md px-2 py-0.5 text-[11px] font-semibold {{ $kindTone[$t['kind']] ?? '' }}">{{ $t['kind_label'] }}</span></td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $t['amount'] ? \App\Support\Rupiah::format($t['amount']) : 'Bebas' }}</td>
                                <td class="px-3 py-3">{{ $t['period_label'] }}</td>
                                <td class="px-3 py-3">{{ $t['withdrawable'] ? 'Boleh ditarik' : 'Ditahan selama anggota' }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if ($this->canManage)
                                        <button type="button" wire:click="toggle({{ $t['id'] }})" class="mr-3 text-sm font-medium text-stone-600 hover:underline">{{ $t['is_active'] ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                        <button type="button" wire:click="edit({{ $t['id'] }})" class="text-sm font-medium text-emerald-700 hover:underline">Ubah</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-stone-400">Belum ada jenis simpanan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <p class="mt-2 text-xs text-stone-500">Kasir tidak bisa membuka menu ini; saldo simpanan hanya dipotong kasir saat anggota membayar belanja.</p>
    </div>

    @if ($editing)
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="st-form-title" x-data x-on:keydown.escape.window="$wire.cancel()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="cancel"></div>
            <form wire:submit="save" class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                    <h2 id="st-form-title" class="font-semibold">{{ $editId ? 'Ubah jenis simpanan' : 'Tambah jenis simpanan' }}</h2>
                    <button type="button" wire:click="cancel" class="text-stone-400 hover:text-stone-600" aria-label="Tutup"><svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
                </div>
                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5 text-sm">
                    <div class="grid grid-cols-3 gap-3">
                        <label class="block"><span class="mb-1 block font-medium">Kode</span>
                            <input type="text" wire:model="form.code" maxlength="30" placeholder="WAJIB" class="{{ $input }} font-mono uppercase">
                            @error('form.code') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>
                        <label class="col-span-2 block"><span class="mb-1 block font-medium">Nama</span>
                            <input type="text" wire:model="form.name" maxlength="60" placeholder="Simpanan Wajib" class="{{ $input }}">
                            @error('form.name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>
                    </div>
                    <fieldset><legend class="mb-1 font-medium">Golongan</legend>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (SavingType::KINDS as $k => $label)
                                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-stone-300 px-3 py-2 has-checked:border-emerald-600 has-checked:bg-emerald-50">
                                    <input type="radio" wire:model.live="form.kind" value="{{ $k }}" class="accent-emerald-700"> {{ $label }}</label>
                            @endforeach
                        </div>
                        @error('form.kind') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </fieldset>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="mb-1 block font-medium">Nominal setor (Rp)</span>
                            <input type="number" min="0" step="1000" wire:model.live.debounce.400ms="form.amount" class="{{ $input }} tabular-nums">
                            <span class="mt-1 block text-xs text-stone-500">0 = bebas</span>
                            @error('form.amount') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>
                        <label class="block"><span class="mb-1 block font-medium">Periode setor</span>
                            <select wire:model.live="form.period" class="{{ $input }}">
                                @foreach (SavingType::PERIODS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                            </select>
                            @error('form.period') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>
                    </div>
                    <label class="flex items-start gap-2"><input type="checkbox" wire:model.live="form.withdrawable" class="mt-0.5 accent-emerald-700">
                        <span><span class="font-medium">Boleh ditarik</span><span class="block text-xs text-stone-500">Matikan untuk simpanan yang ditahan selama masih menjadi anggota (pokok & wajib).</span></span></label>
                    <label class="block"><span class="mb-1 block font-medium">Keterangan <span class="font-normal text-stone-500">(opsional)</span></span>
                        <input type="text" wire:model="form.description" maxlength="200" class="{{ $input }}">
                        @error('form.description') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.is_active" class="accent-emerald-700"> Aktif (bisa dipakai untuk setoran baru)</label>
                    <p class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600" data-rule-summary
                       wire:key="rule-{{ ($form['amount'] ?? 0).($form['period'] ?? '').(int) ($form['withdrawable'] ?? 0) }}">{{ \App\Services\SavingTypeService::ruleSentence((int) ($form['amount'] ?? 0), (string) ($form['period'] ?? 'bebas'), (bool) ($form['withdrawable'] ?? false)) }}</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-stone-200 px-5 py-3">
                    <button type="button" wire:click="cancel" class="rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100">Batal</button>
                    <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Simpan</button>
                </div>
            </form>
        </div>
    @endif
</div>
