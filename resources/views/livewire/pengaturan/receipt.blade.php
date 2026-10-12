@php
    $input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
    $label = 'mb-1 block text-sm font-medium text-stone-700';
    $preview = $this->previewProfile();
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    $mm = \App\Services\ReceiptPresenter::paperWidth($preview);
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Pengaturan Koperasi</h1>
        <p class="text-xs text-stone-500">Atur tulisan yang muncul di struk pembelian.</p>
        <x-pengaturan.nav />
    </header>

    @unless ($this->canEdit)
        <p class="mx-4 mt-4 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:mx-6" role="status">
            <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            Mode baca saja — hanya Admin yang dapat mengubah pengaturan koperasi.
        </p>
    @endunless
    <form wire:submit="save" class="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]" novalidate>
        <fieldset class="contents" @disabled(! $this->canEdit)>
        <div class="min-w-0 space-y-5">

            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="rc-head">
                <h2 id="rc-head" class="font-semibold">Kepala struk</h2>
                <label class="block"><span class="{{ $label }}">Judul</span>
                    <input type="text" wire:model.live.debounce.300ms="header" maxlength="40" class="{{ $input }}" placeholder="{{ \App\Support\MockData::profile()['name'] }}">
                    <span class="mt-1 block text-[11px] text-stone-500">Kosongkan untuk memakai nama koperasi.</span>
                    @error('header') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block"><span class="{{ $label }}">Sub-judul</span>
                    <input type="text" wire:model.live.debounce.300ms="subheader" maxlength="60" class="{{ $input }}" placeholder="{{ \App\Support\MockData::profile()['school'] }}">
                    @error('subheader') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (['show_address' => 'Tampilkan alamat', 'show_phone' => 'Tampilkan telepon', 'show_cashier' => 'Tampilkan nama kasir', 'show_member' => 'Tampilkan nama anggota'] as $field => $text)
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-stone-200 px-3 py-2.5 text-sm has-checked:border-emerald-600 has-checked:bg-emerald-50">
                            <input type="checkbox" wire:model.live="{{ $field }}" class="size-4 accent-emerald-700"> {{ $text }}
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="rc-foot">
                <h2 id="rc-foot" class="font-semibold">Kaki struk</h2>
                <label class="block"><span class="{{ $label }}">Catatan <span class="font-normal text-stone-500">(opsional)</span></span>
                    <textarea wire:model.live.debounce.300ms="note" rows="2" maxlength="160" class="{{ $input }}" placeholder="mis. Barang yang sudah dibeli tidak dapat ditukar."></textarea>
                    @error('note') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block"><span class="{{ $label }}">Tulisan penutup</span>
                    <textarea wire:model.live.debounce.300ms="footer" rows="2" maxlength="160" class="{{ $input }}"></textarea>
                    <span class="mt-1 flex justify-between text-[11px] text-stone-500"><span>Maks. 3 baris.</span><span class="tabular-nums">{{ mb_strlen($footer) }}/160</span></span>
                    @error('footer') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="rc-paper">
                <h2 id="rc-paper" class="font-semibold">Ukuran kertas</h2>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach (\App\Livewire\Pengaturan\Receipt::PAPERS as $value => $text)
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-stone-300 px-3 py-2.5 text-sm has-checked:border-emerald-600 has-checked:bg-emerald-50">
                            <input type="radio" value="{{ $value }}" wire:model.live="paper" class="accent-emerald-700"> {{ $text }}
                        </label>
                    @endforeach
                </div>
                @error('paper') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <button type="button" wire:click="resetDefaults" class="mr-auto text-sm font-medium text-stone-500 hover:text-stone-800">Kembalikan bawaan</button>
                <a href="{{ route('pengaturan') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Kembali</a>
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Simpan struk</button>
            </div>
        </div>

        <aside class="h-fit lg:sticky lg:top-4" aria-label="Pratinjau struk">
            <p class="mb-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">Pratinjau · {{ $mm }} mm</p>
            <div class="rounded-2xl bg-stone-200/70 p-4">
                <div class="mx-auto max-w-full rounded-sm bg-white p-3 shadow-sm" style="width: {{ $mm }}mm">
                    @include('livewire.kasir.partials.receipt', ['tx' => $this->sample(), 'profile' => $preview])
                </div>
            </div>
            <p class="mt-2 text-xs text-stone-500">Contoh transaksi. Struk asli mengikuti barang yang dibeli.</p>
        </aside>
        </fieldset>
    </form>
</div>
