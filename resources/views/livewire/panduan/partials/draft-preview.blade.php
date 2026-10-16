{{-- Pratinjau draf panduan per menu: draf terbaru dibanding isi otomatis tersimpan --}}
@php $fieldLabels = ['title' => 'Judul', 'summary' => 'Ringkasan', 'steps' => 'Langkah', 'tips' => 'Tips', 'related' => 'Halaman terkait']; @endphp
<div class="space-y-4 px-4 py-5 sm:px-6" data-guide-draft>
    <div class="flex flex-wrap items-end gap-3">
        <label class="min-w-64 flex-1 text-xs text-stone-600">Panduan
            <select wire:model.live="draftKey" class="mt-1 w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm" data-guide-draft-select>
                @foreach ($this->draftOptions as $k => $label)
                    <option value="{{ $k }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        @if ($p)
            @php $changes = collect($p['diff'])->filter(fn ($d) => $d['changed'])->keys(); @endphp
            <p class="text-sm text-stone-600" data-guide-draft-status>
                @if ($p['manual'])
                    Panduan manual — draf otomatis tidak akan diterapkan.
                @elseif (! $p['stored'])
                    Panduan baru — belum tersimpan.
                @elseif ($changes->isEmpty())
                    Draf sama dengan isi tersimpan.
                @else
                    Berubah: {{ $changes->map(fn ($f) => mb_strtolower($fieldLabels[$f]))->join(', ') }}.
                @endif
            </p>
        @endif
    </div>

    @if ($draftApplied)
        <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-900" role="status" data-guide-draft-applied>Draf {{ $draftApplied }}. Perubahan tercatat di versi panduan dan Audit.</p>
    @endif

    @if ($p)
        @if ($p['overridden'])
            <p class="rounded-xl bg-sky-50 px-4 py-2 text-sm text-sky-900 ring-1 ring-sky-200" data-guide-draft-overridden>
                Bagian {{ collect($p['overridden'])->map(fn ($f) => mb_strtolower($fieldLabels[$f] ?? $f))->join(', ') }} sedang disunting admin — suntingan itu tetap yang tampil setelah draf diterapkan.
            </p>
        @endif

        <div class="space-y-3">
            @foreach ($fieldLabels as $field => $label)
                @php $d = $p['diff'][$field]; @endphp
                <section @class(['rounded-2xl border bg-white p-4', 'border-sky-300' => $d['changed'], 'border-stone-200' => ! $d['changed']]) data-draft-field="{{ $field }}" data-changed="{{ $d['changed'] ? '1' : '0' }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-sm font-semibold">{{ $label }}</h3>
                        @if ($d['changed'])<span class="rounded bg-sky-100 px-1.5 py-0.5 text-[11px] font-semibold text-sky-800">berubah</span>@endif
                        @if (in_array($field, $p['overridden'], true))<span class="rounded bg-stone-100 px-1.5 py-0.5 text-[11px] font-semibold text-stone-600">disunting admin</span>@endif
                    </div>
                    @if (in_array($field, ['title', 'summary'], true))
                        @if ($d['changed'] && $p['stored'])
                            <p class="mt-2 text-sm text-red-800 line-through decoration-red-300" data-draft-old>{{ $p['stored'][$field] }}</p>
                        @endif
                        <p class="mt-1 text-sm text-stone-800" data-draft-new>{{ $p['draft'][$field] }}</p>
                    @else
                        @php
                            $rows = match ($field) {
                                'steps' => array_map(fn ($s) => $s['text'].(isset($s['route']) ? " → {$s['route']}" : ''), $p['draft']['steps']),
                                'tips' => $p['draft']['tips'],
                                default => array_map(fn ($r, $l) => "{$l} ({$r})", array_keys($p['draft']['related']), $p['draft']['related']),
                            };
                        @endphp
                        <ul class="mt-2 space-y-1 text-sm">
                            @forelse ($rows as $row)
                                <li @class(['rounded px-2 py-0.5', 'bg-emerald-50 text-emerald-900' => in_array($row, $d['added'], true), 'text-stone-800' => ! in_array($row, $d['added'], true)])
                                    @if (in_array($row, $d['added'], true)) data-draft-added @endif>{{ in_array($row, $d['added'], true) ? '+ ' : '' }}{{ $row }}</li>
                            @empty
                                <li class="text-stone-500">—</li>
                            @endforelse
                            @foreach ($d['removed'] as $row)
                                <li class="rounded bg-red-50 px-2 py-0.5 text-red-800 line-through decoration-red-300" data-draft-removed>− {{ $row }}</li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>

        @if (! $p['manual'] && (! $p['stored'] || collect($p['diff'])->contains(fn ($d) => $d['changed'])))
            <div class="flex justify-end">
                <button type="button" wire:click="applyDraft" wire:loading.attr="disabled" wire:target="applyDraft" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800" data-guide-draft-apply>Terapkan draf panduan ini</button>
            </div>
        @endif
    @endif
</div>
