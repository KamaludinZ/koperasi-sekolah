@php
    $fields = \App\Models\GuideOverride::FIELDS;
    $input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm';
    $max = \App\Services\GuideService::MAX;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex flex-wrap items-end justify-between gap-3 border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Sunting Panduan</h1>
            <p class="text-xs text-stone-500">Suntingan disimpan per bagian di atas isi otomatis — tetap dipakai walau panduan disusun ulang, dan bisa dikembalikan ke hasil otomatis kapan saja.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <a href="{{ route('panduan', ['menu' => $key]) }}" wire:navigate class="font-medium text-emerald-800 hover:underline" data-editor-view>Lihat panduan</a>
            <a href="{{ route('panduan.pembaruan') }}" wire:navigate class="font-medium text-emerald-800 hover:underline">Pembaruan panduan</a>
        </div>
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 xl:grid-cols-[minmax(0,1fr)_18rem]">
        <div class="space-y-4">
            <div class="flex flex-wrap items-end gap-3">
                <label class="min-w-64 flex-1 text-xs text-stone-600">Panduan
                    <select wire:model.live="key" class="mt-1 {{ $input }}" data-editor-select>
                        @foreach ($this->options as $group => $items)
                            <optgroup label="{{ $group }}">
                                @foreach ($items as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </label>
                <label class="min-w-64 flex-1 text-xs text-stone-600">Catatan perubahan (opsional, tercatat di versi & audit)
                    <input type="text" wire:model="note" maxlength="200" class="mt-1 {{ $input }}" placeholder="mis. sesuaikan dengan alur koperasi kami" data-editor-note>
                </label>
            </div>
            @if ($this->entry?->is_stale)
                <p class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200" data-editor-stale>Panduan ini ditandai perlu ditinjau: {{ $this->entry->stale_reason }}</p>
            @endif
            @if ($saved)
                <p class="rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-900 ring-1 ring-emerald-200" role="status" data-editor-saved>{{ $saved }} Versi baru & jejak audit dicatat.</p>
            @endif

            @if ($this->entry)
                @foreach ($fields as $field => $label)
                    @php $o = $overrides[$field] ?? null; $dirty = $this->dirty($field); @endphp
                    <section @class(['rounded-2xl border bg-white p-4', 'border-sky-300' => $o, 'border-stone-200' => ! $o]) wire:key="sec-{{ $key }}-{{ $field }}" data-editor-field="{{ $field }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="flex flex-wrap items-center gap-2 text-sm font-semibold">{{ $label }}
                                @if ($o)
                                    <span class="rounded bg-sky-100 px-1.5 py-0.5 text-[11px] font-semibold text-sky-800" data-editor-overridden="{{ $field }}">Disunting{{ $o->editor ? ' oleh '.$o->editor->name : '' }} · {{ $o->updated_at?->translatedFormat('j M Y H:i') }}</span>
                                @else
                                    <span class="rounded bg-stone-100 px-1.5 py-0.5 text-[11px] font-semibold text-stone-600">Otomatis</span>
                                @endif
                                @if ($dirty)<span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-900" data-editor-dirty="{{ $field }}">Belum disimpan</span>@endif
                            </h2>
                            <div class="flex flex-wrap gap-2 text-xs">
                                @if ($dirty)
                                    <button type="button" wire:click="discard('{{ $field }}')" class="rounded-lg px-2.5 py-1 font-medium text-stone-600 hover:bg-stone-100">Batal</button>
                                @endif
                                @if ($o)
                                    <button type="button" wire:click="revert('{{ $field }}')" wire:confirm="Kembalikan {{ mb_strtolower($label) }} ke hasil otomatis? Suntingan bagian ini dihapus (tetap tersimpan di riwayat versi)." class="rounded-lg border border-stone-300 px-2.5 py-1 font-medium hover:bg-stone-50" data-editor-revert="{{ $field }}">Kembalikan ke otomatis</button>
                                @endif
                                <button type="button" wire:click="save('{{ $field }}')" @disabled(! $dirty) class="rounded-lg bg-emerald-700 px-3 py-1 font-semibold text-white hover:bg-emerald-800 disabled:opacity-40" data-editor-save="{{ $field }}">Simpan {{ mb_strtolower($label) }}</button>
                            </div>
                        </div>

                        <div class="mt-3">
                            @if ($field === 'title')
                                <input type="text" wire:model.live.debounce.400ms="title" maxlength="{{ $max['title'] }}" class="{{ $input }}" data-editor-input="title">
                            @elseif ($field === 'summary')
                                <textarea wire:model.live.debounce.400ms="summary" rows="3" maxlength="{{ $max['summary'] }}" class="{{ $input }}" data-editor-input="summary"></textarea>
                            @elseif ($field === 'steps')
                                <ol class="space-y-2">
                                    @foreach ($steps as $i => $step)
                                        <li class="flex flex-wrap items-start gap-2" wire:key="step-{{ $key }}-{{ $i }}">
                                            <span class="mt-2 grid size-6 shrink-0 place-items-center rounded-full bg-emerald-700 text-[11px] font-bold text-white">{{ $i + 1 }}</span>
                                            <textarea wire:model.live.debounce.400ms="steps.{{ $i }}.text" rows="1" maxlength="{{ $max['step'] }}" class="min-w-48 flex-1 {{ $input }}" data-editor-step="{{ $i }}"></textarea>
                                            <select wire:model.live="steps.{{ $i }}.route" class="w-48 {{ $input }}" aria-label="Tautan halaman langkah {{ $i + 1 }}">
                                                <option value="">Tanpa tautan</option>
                                                @foreach ($this->pageOptions as $r => $t)<option value="{{ $r }}">{{ $t }}</option>@endforeach
                                            </select>
                                            <span class="flex gap-1">
                                                <button type="button" wire:click="moveStep({{ $i }}, -1)" @disabled($i === 0) class="rounded px-1.5 py-1 text-stone-600 hover:bg-stone-100 disabled:opacity-30" aria-label="Naikkan langkah {{ $i + 1 }}">↑</button>
                                                <button type="button" wire:click="moveStep({{ $i }}, 1)" @disabled($i === count($steps) - 1) class="rounded px-1.5 py-1 text-stone-600 hover:bg-stone-100 disabled:opacity-30" aria-label="Turunkan langkah {{ $i + 1 }}">↓</button>
                                                <button type="button" wire:click="removeStep({{ $i }})" class="rounded px-1.5 py-1 text-red-700 hover:bg-red-50" aria-label="Hapus langkah {{ $i + 1 }}">✕</button>
                                            </span>
                                        </li>
                                    @endforeach
                                </ol>
                                @if (count($steps) < $max['steps'])
                                    <button type="button" wire:click="addStep" class="mt-2 text-xs font-semibold text-emerald-800 hover:underline" data-editor-add-step>+ Tambah langkah</button>
                                @endif
                            @else
                                <ul class="space-y-2">
                                    @foreach ($tips as $i => $tip)
                                        <li class="flex gap-2" wire:key="tip-{{ $key }}-{{ $i }}">
                                            <input type="text" wire:model.live.debounce.400ms="tips.{{ $i }}" maxlength="{{ $max['tip'] }}" class="flex-1 {{ $input }}" data-editor-tip="{{ $i }}">
                                            <button type="button" wire:click="removeTip({{ $i }})" class="rounded px-1.5 py-1 text-red-700 hover:bg-red-50" aria-label="Hapus tips {{ $i + 1 }}">✕</button>
                                        </li>
                                    @endforeach
                                </ul>
                                @if (count($tips) < $max['tips'])
                                    <button type="button" wire:click="addTip" class="mt-2 text-xs font-semibold text-emerald-800 hover:underline" data-editor-add-tip>+ Tambah tips</button>
                                @endif
                            @endif
                            @error($field)<p class="mt-1 text-xs text-red-700" data-editor-error="{{ $field }}">{{ $message }}</p>@enderror
                        </div>

                        @if ($o)
                            <details class="mt-3 rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600" data-editor-auto="{{ $field }}">
                                <summary class="cursor-pointer font-medium">Isi otomatis (dipakai bila dikembalikan)</summary>
                                <div class="mt-1">
                                    @if (in_array($field, ['title', 'summary'], true))
                                        <p>{{ $auto[$field] }}</p>
                                    @else
                                        <ol class="list-decimal space-y-0.5 pl-5">
                                            @foreach ($auto[$field] as $item)<li>{{ is_array($item) ? $item['text'] : $item }}</li>@endforeach
                                        </ol>
                                    @endif
                                </div>
                            </details>
                        @endif
                    </section>
                @endforeach
            @endif
        </div>

        <aside class="space-y-3 xl:sticky xl:top-0 xl:self-start" aria-label="Riwayat versi">
            <div class="rounded-2xl border border-stone-200 bg-white p-4 text-sm">
                <h2 class="font-semibold">Versi terakhir</h2>
                <ul class="mt-2 space-y-2 text-xs">
                    @forelse ($this->versions as $v)
                        <li wire:key="ver-{{ $v->id }}">
                            <p><span class="font-semibold">v{{ $v->version }}</span> · {{ \App\Models\GuideVersion::TYPES[$v->change_type] ?? $v->change_type }}</p>
                            <p class="text-stone-500">{{ $v->created_at?->translatedFormat('j M Y H:i') }} · {{ $v->author?->name ?? 'Sistem' }}@if ($v->change_note) · {{ $v->change_note }}@endif</p>
                        </li>
                    @empty
                        <li class="text-stone-500">Belum ada versi.</li>
                    @endforelse
                </ul>
            </div>
            @if ($this->entry)
                <p class="px-1 text-xs text-stone-500">Sumber: {{ \App\Models\GuideEntry::SOURCES[$this->entry->source] ?? $this->entry->source }} · {{ \App\Models\GuideEntry::AUDIENCES[$this->entry->audience] ?? $this->entry->audience }}</p>
            @endif
        </aside>
    </div>
</div>
