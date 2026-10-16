@php
    $typeBadge = fn (string $t) => match ($t) {
        'override' => 'bg-sky-100 text-sky-800', 'revert' => 'bg-stone-200 text-stone-700', 'restore' => 'bg-violet-100 text-violet-800', default => 'bg-emerald-100 text-emerald-800',
    };
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex flex-wrap items-end justify-between gap-3 border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Riwayat Versi Panduan</h1>
            <p class="text-xs text-stone-500">Setiap perubahan isi panduan menjadi versi bernomor — disusun otomatis, disunting, dikembalikan, atau dipulihkan.</p>
        </div>
        <div class="flex flex-wrap gap-3 text-sm">
            <a href="{{ route('panduan') }}" wire:navigate class="font-medium text-emerald-800 hover:underline">← Pusat Panduan</a>
            @can('kelola-panduan')
                <a href="{{ route('panduan.sunting', $key !== '' ? ['panduan' => $key] : []) }}" wire:navigate class="font-medium text-emerald-800 hover:underline" data-versions-editor-link>Sunting panduan</a>
            @endcan
        </div>
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="flex flex-wrap items-end gap-3">
            <label class="min-w-64 flex-1 text-xs text-stone-600">Panduan
                <select wire:model.live="key" class="mt-1 w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm" data-versions-select>
                    <option value="">Semua panduan (versi terbaru)</option>
                    @foreach ($this->options as $group => $items)
                        <optgroup label="{{ $group }}">@foreach ($items as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach</optgroup>
                    @endforeach
                </select>
            </label>
            <div class="flex flex-wrap gap-1.5 text-xs" role="group" aria-label="Saring jenis perubahan">
                <button type="button" wire:click="$set('type', '')" aria-pressed="{{ $type === '' ? 'true' : 'false' }}" data-versions-type=""
                        @class(['rounded-full px-3 py-1 font-medium', 'bg-emerald-700 text-white' => $type === '', 'border border-stone-300 bg-white text-stone-700 hover:bg-stone-50' => $type !== ''])>Semua ({{ array_sum($this->typeCounts) }})</button>
                @foreach (\App\Models\GuideVersion::TYPES as $t => $label)
                    <button type="button" wire:click="$set('type', '{{ $t }}')" aria-pressed="{{ $type === $t ? 'true' : 'false' }}" data-versions-type="{{ $t }}"
                            @class(['rounded-full px-3 py-1 font-medium', 'bg-emerald-700 text-white' => $type === $t, 'border border-stone-300 bg-white text-stone-700 hover:bg-stone-50' => $type !== $t])>{{ $label }} ({{ $this->typeCounts[$t] }})</button>
                @endforeach
            </div>
        </div>

        @if ($this->entry)
            <div class="rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm" data-versions-entry>
                <p class="font-semibold">{{ $this->entry->title }}</p>
                <p class="text-xs text-stone-500">{{ \App\Models\GuideEntry::SOURCES[$this->entry->source] ?? $this->entry->source }} · {{ $this->versions->total() }} versi{{ $this->entry->generated_at ? ' · terakhir disusun '.$this->entry->generated_at->translatedFormat('j M Y H:i') : '' }}</p>
            </div>
        @endif

        <ol class="space-y-2">
            @forelse ($this->versions as $v)
                @php $changed = $this->changedFields($v); @endphp
                <li class="rounded-2xl border border-stone-200 bg-white p-4 text-sm" wire:key="v-{{ $v->id }}" data-version="{{ $v->guide_entry_id }}:{{ $v->version }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-semibold">v{{ $v->version }}</span>
                        <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold {{ $typeBadge($v->change_type) }}">{{ \App\Models\GuideVersion::TYPES[$v->change_type] ?? $v->change_type }}</span>
                        @unless ($this->entry)
                            <button type="button" wire:click="$set('key', '{{ $v->entry?->key }}')" class="font-medium text-emerald-800 hover:underline" data-versions-open="{{ $v->entry?->key }}">{{ $v->entry?->title }}</button>
                        @endunless
                        <span class="text-xs text-stone-500">{{ $v->created_at?->translatedFormat('j M Y H:i') }} · {{ $v->author?->name ?? 'Sistem' }}@if ($v->sync_job_id) · sinkronisasi #{{ $v->sync_job_id }}@endif</span>
                    </div>
                    @if ($v->change_note)<p class="mt-1 text-stone-700">{{ $v->change_note }}</p>@endif
                    <p class="mt-1 text-xs text-stone-500" data-version-changed>
                        {{ $changed === null ? 'Versi awal' : ($changed ? 'Berubah: '.implode(', ', array_map('mb_strtolower', $changed)) : 'Tanpa perubahan isi') }}
                        · {{ $v->snapshot['title'] ?? '' }}
                    </p>
                </li>
            @empty
                <li class="rounded-2xl border border-stone-200 bg-white px-4 py-8 text-center text-sm text-stone-500" data-versions-empty>Belum ada versi untuk saringan ini.</li>
            @endforelse
        </ol>
        {{ $this->versions->links() }}
    </div>
</div>
