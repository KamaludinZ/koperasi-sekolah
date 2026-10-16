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
                <p class="text-xs text-stone-500">{{ \App\Models\GuideEntry::SOURCES[$this->entry->source] ?? $this->entry->source }} · {{ $this->versions->total() }} versi{{ $this->entry->generated_at ? ' · terakhir disusun '.$this->entry->generated_at->translatedFormat('j M Y H:i') : '' }}
                    @if ($this->active[$this->entry->id] ?? null) · versi aktif <strong>v{{ $this->active[$this->entry->id] }}</strong>@endif</p>
                @if (($this->active[$this->entry->id] ?? null) === null)
                    <p class="mt-1 text-xs text-amber-800" data-versions-no-active>Isi yang tampil sekarang belum tercatat sebagai versi mana pun — versi baru tercatat saat panduan disunting atau disusun ulang.</p>
                @endif
            </div>
        @endif

        @if ($rp = $this->restorePreview)
            @php $tv = $rp['target']; $d = $rp['diff']; @endphp
            <section class="rounded-2xl border-2 border-violet-400 bg-white p-4 text-sm shadow-sm" role="dialog" aria-modal="false" aria-labelledby="restore-title" data-restore-dialog="{{ $tv->version }}">
                <h2 id="restore-title" class="font-semibold">Pulihkan panduan ke isi versi v{{ $tv->version }}?</h2>
                <p class="text-xs text-stone-500">Versi ini dari {{ $tv->created_at?->translatedFormat('j M Y H:i') }} ({{ \App\Models\GuideVersion::TYPES[$tv->change_type] ?? $tv->change_type }}, {{ $tv->author?->name ?? 'Sistem' }}). Isi yang tampil akan diganti; riwayat lama tetap tersimpan dan pemulihan dicatat sebagai versi baru & di Audit.</p>
                @if (! $d['changed'])
                    <p class="mt-3 rounded-lg bg-stone-50 px-3 py-2 text-stone-600" data-restore-same>Isi panduan sekarang sudah sama dengan versi ini.</p>
                @else
                    <p class="mt-3 text-xs font-semibold text-stone-500 uppercase">Yang akan berubah</p>
                    @foreach ($d['fields'] as $f => $fd)
                        @continue(! $fd['changed'])
                        <div class="mt-2" data-restore-field="{{ $f }}">
                            <p class="text-xs font-semibold">{{ \App\Support\GuideVersionDiff::label($f) }}</p>
                            @if ($fd['type'] === 'text')
                                <p class="rounded bg-red-50 px-2 py-1 text-red-800 line-through decoration-red-300">{{ $fd['old'] }}</p>
                                <p class="mt-1 rounded bg-emerald-50 px-2 py-1 text-emerald-900">{{ $fd['new'] }}</p>
                            @else
                                <ul class="space-y-0.5">@foreach ($fd['lines'] as $l)<li @class(['rounded px-2 py-0.5', 'bg-emerald-50 text-emerald-900' => $l['op'] === 'add', 'bg-red-50 text-red-800 line-through' => $l['op'] === 'del', 'text-stone-700' => $l['op'] === 'same'])>{{ ['same' => '  ', 'add' => '+ ', 'del' => '− '][$l['op']] }}{{ $l['text'] }}</li>@endforeach</ul>
                            @endif
                        </div>
                    @endforeach
                @endif
                <label class="mt-3 block text-xs text-stone-600">Alasan pemulihan (wajib, tercatat di versi & Audit)
                    <input type="text" wire:model="reason" maxlength="200" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" placeholder="mis. suntingan terakhir keliru, kembali ke versi yang disetujui pengurus" data-restore-reason>
                </label>
                @error('reason')<p class="mt-1 text-xs text-red-700" data-restore-error>{{ $message }}</p>@enderror
                <div class="mt-3 flex justify-end gap-2">
                    <button type="button" wire:click="cancelRestore" class="rounded-xl px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100" data-restore-cancel>Batal</button>
                    <button type="button" wire:click="confirmRestore" @disabled(! $d['changed']) class="rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-800 disabled:opacity-40" data-restore-confirm>Ya, pulihkan v{{ $tv->version }}</button>
                </div>
            </section>
        @endif

        @if ($cmp = $this->comparison)
            @php $opClass = ['same' => 'text-stone-700', 'add' => 'bg-emerald-50 text-emerald-900', 'del' => 'bg-red-50 text-red-800 line-through decoration-red-300']; @endphp
            <section class="rounded-2xl border-2 border-sky-300 bg-white p-4 text-sm" aria-labelledby="cmp-title" data-versions-compare="{{ $from }}-{{ $to }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="cmp-title" class="font-semibold">Perbandingan v{{ $from }} → v{{ $to }}</h2>
                    <button type="button" wire:click="closeCompare" class="text-xs font-medium text-stone-600 hover:underline" data-compare-close>Tutup</button>
                </div>
                <p class="text-xs text-stone-500">v{{ $from }}: {{ \App\Models\GuideVersion::TYPES[$cmp['a']->change_type] ?? '' }} · {{ $cmp['a']->created_at?->translatedFormat('j M Y H:i') }} · {{ $cmp['a']->author?->name ?? 'Sistem' }}
                    — v{{ $to }}: {{ \App\Models\GuideVersion::TYPES[$cmp['b']->change_type] ?? '' }} · {{ $cmp['b']->created_at?->translatedFormat('j M Y H:i') }} · {{ $cmp['b']->author?->name ?? 'Sistem' }}</p>
                @if (! $cmp['diff']['changed'] && ! $cmp['diff']['overridden']['added'] && ! $cmp['diff']['overridden']['removed'])
                    <p class="mt-3 rounded-lg bg-stone-50 px-3 py-2 text-stone-600" data-compare-same>Isi kedua versi sama.</p>
                @endif
                @foreach ($cmp['diff']['fields'] as $f => $d)
                    @continue(! $d['changed'])
                    <div class="mt-3" data-compare-field="{{ $f }}">
                        <p class="text-xs font-semibold text-stone-500 uppercase">{{ \App\Support\GuideVersionDiff::label($f) }}</p>
                        @if ($d['type'] === 'text')
                            <p class="mt-1 rounded bg-red-50 px-2 py-1 text-red-800 line-through decoration-red-300" data-compare-old>{{ $d['old'] }}</p>
                            <p class="mt-1 rounded bg-emerald-50 px-2 py-1 text-emerald-900" data-compare-new>{{ $d['new'] }}</p>
                        @else
                            <ul class="mt-1 space-y-0.5">
                                @foreach ($d['lines'] as $l)
                                    <li class="rounded px-2 py-0.5 {{ $opClass[$l['op']] }}" data-compare-line="{{ $l['op'] }}">{{ ['same' => '  ', 'add' => '+ ', 'del' => '− '][$l['op']] }}{{ $l['text'] }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
                @if ($cmp['diff']['overridden']['added'] || $cmp['diff']['overridden']['removed'])
                    <p class="mt-3 text-xs text-sky-800" data-compare-overridden>
                        @if ($cmp['diff']['overridden']['added'])Mulai disunting manual: {{ collect($cmp['diff']['overridden']['added'])->map(fn ($f) => mb_strtolower(\App\Support\GuideVersionDiff::label($f)))->join(', ') }}. @endif
                        @if ($cmp['diff']['overridden']['removed'])Kembali ke otomatis: {{ collect($cmp['diff']['overridden']['removed'])->map(fn ($f) => mb_strtolower(\App\Support\GuideVersionDiff::label($f)))->join(', ') }}.@endif
                    </p>
                @endif
            </section>
        @elseif ($this->entry && ($from || $to))
            <p class="rounded-xl bg-sky-50 px-3 py-2 text-xs text-sky-900 ring-1 ring-sky-200" data-compare-pending>Pilih versi {{ $from ? 'baru (B)' : 'lama (A)' }} untuk dibandingkan dengan v{{ $from ?: $to }}.</p>
        @endif

        <ol class="space-y-2">
            @forelse ($this->versions as $v)
                @php $changed = $this->changedFields($v); @endphp
                @php $isActive = ($this->active[$v->guide_entry_id] ?? null) === $v->version; @endphp
                <li @class(['rounded-2xl border bg-white p-4 text-sm', 'border-emerald-500 ring-2 ring-emerald-500/20' => $isActive, 'border-stone-200' => ! $isActive])
                    wire:key="v-{{ $v->id }}" data-version="{{ $v->guide_entry_id }}:{{ $v->version }}" @if ($isActive) data-version-active aria-current="true" @endif>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-semibold">v{{ $v->version }}</span>
                        @if ($isActive)<span class="rounded bg-emerald-700 px-1.5 py-0.5 text-[11px] font-semibold text-white">Aktif</span>@endif
                        <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold {{ $typeBadge($v->change_type) }}">{{ \App\Models\GuideVersion::TYPES[$v->change_type] ?? $v->change_type }}</span>
                        @unless ($this->entry)
                            <button type="button" wire:click="$set('key', '{{ $v->entry?->key }}')" class="font-medium text-emerald-800 hover:underline" data-versions-open="{{ $v->entry?->key }}">{{ $v->entry?->title }}</button>
                        @endunless
                        <span class="text-xs text-stone-500">{{ $v->created_at?->translatedFormat('j M Y H:i') }} · {{ $v->author?->name ?? 'Sistem' }}@if ($v->sync_job_id) · sinkronisasi #{{ $v->sync_job_id }}@endif</span>
                    </div>
                    @if ($v->change_note)<p class="mt-1 text-stone-700">{{ $v->change_note }}</p>@endif
                    @if ($this->entry)
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            @if ($v->version > 1)
                                <button type="button" wire:click="compareWithPrevious({{ $v->version }})" class="rounded-lg border border-sky-300 px-2.5 py-1 font-medium text-sky-800 hover:bg-sky-50" data-compare-prev="{{ $v->version }}">Bandingkan dengan v{{ $v->version - 1 }}</button>
                            @endif
                            <button type="button" wire:click="pick('dari', {{ $v->version }})" @class(['rounded-lg border px-2.5 py-1 font-medium', 'border-sky-600 bg-sky-600 text-white' => $from === $v->version, 'border-stone-300 hover:bg-stone-50' => $from !== $v->version]) data-compare-pick-a="{{ $v->version }}">Jadikan A</button>
                            @if (! $isActive && \Illuminate\Support\Facades\Gate::allows('kelola-panduan'))
                                <button type="button" wire:click="startRestore({{ $v->version }})" class="rounded-lg border border-violet-300 px-2.5 py-1 font-medium text-violet-800 hover:bg-violet-50" data-restore-start="{{ $v->version }}">Pulihkan versi ini</button>
                            @endif
                            <button type="button" wire:click="pick('ke', {{ $v->version }})" @class(['rounded-lg border px-2.5 py-1 font-medium', 'border-sky-600 bg-sky-600 text-white' => $to === $v->version, 'border-stone-300 hover:bg-stone-50' => $to !== $v->version]) data-compare-pick-b="{{ $v->version }}">Jadikan B</button>
                        </div>
                    @endif
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
