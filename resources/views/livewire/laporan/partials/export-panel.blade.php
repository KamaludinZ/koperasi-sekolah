{{-- Panel unduh laporan dengan pemilih rentang tanggal --}}
@php $date = 'rounded-lg border border-stone-300 bg-white px-2.5 py-1.5 text-sm outline-none focus:border-emerald-600'; @endphp
<section class="rounded-xl border border-stone-200 bg-white" aria-labelledby="export-title">
    <form wire:submit="export" class="grid gap-5 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
        <div>
            <h2 id="export-title" class="text-sm font-semibold">Unduh laporan</h2>
            <p class="mb-3 text-xs text-stone-500">Rekap penjualan untuk dilaporkan ke sekolah.</p>
            <div class="flex flex-wrap items-end gap-2">
                <label class="text-xs text-stone-600"><span class="mb-1 block">Dari</span>
                    <input type="date" wire:model="exportFrom" max="{{ now()->toDateString() }}" class="{{ $date }}">
                </label>
                <label class="text-xs text-stone-600"><span class="mb-1 block">Sampai</span>
                    <input type="date" wire:model="exportTo" max="{{ now()->toDateString() }}" class="{{ $date }}">
                </label>
                <button type="button" wire:click="syncExportRange" class="px-1 py-1.5 text-xs font-medium text-emerald-700 hover:underline">Samakan dengan periode</button>
            </div>
            @error('exportFrom') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('exportTo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="space-y-3">
            <fieldset>
                <legend class="mb-1 text-xs text-stone-600">Isi laporan</legend>
                <div class="grid grid-cols-2 gap-x-3 gap-y-1">
                    @foreach (\App\Livewire\Laporan\Index::EXPORT_SECTIONS as $key => $label)
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" value="{{ $key }}" wire:model="exportSections" class="size-4 accent-emerald-700"> {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('exportSections') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </fieldset>
            <fieldset>
                <legend class="mb-1 text-xs text-stone-600">Format</legend>
                <div class="flex gap-2">
                    @foreach (\App\Livewire\Laporan\Index::EXPORT_FORMATS as $value => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-stone-300 px-3 py-1.5 text-sm has-checked:border-emerald-600 has-checked:bg-emerald-50">
                            <input type="radio" value="{{ $value }}" wire:model="exportFormat" class="accent-emerald-700"> {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('exportFormat') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </fieldset>
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="export"
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 4v12m0 0-4-4m4 4 4-4M4 20h16"/></svg>
            <span wire:loading.remove wire:target="export">Unduh rekap</span>
            <span wire:loading wire:target="export">Menyiapkan…</span>
        </button>
    </form>
</section>
