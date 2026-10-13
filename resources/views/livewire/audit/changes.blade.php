@use('App\Livewire\Audit\Changes')
@php
    $roles = \App\Models\Role::LABELS;
    $s = $this->summary;
    $filtered = $this->filtered;
    $actionTone = [
        'dibuat' => 'bg-emerald-100 text-emerald-800',
        'diubah' => 'bg-sky-100 text-sky-800',
        'dihapus' => 'bg-red-100 text-red-800',
        'dipulihkan' => 'bg-amber-100 text-amber-900',
    ];
    $hasFilter = $entity !== '' || $action !== '' || $actor !== '' || $search !== '' || $subject !== '' || $sensitiveOnly;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Audit & Riwayat</h1>
                <p class="text-xs text-stone-500">Siapa mengubah data barang, kategori, anggota, dan pengaturan — beserta nilai sebelum & sesudahnya.</p>
            </div>
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">Data contoh</span>
        </div>
        <x-audit.nav active="audit.perubahan" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6" wire:loading.class="opacity-60" wire:target="setEntity,setAction,setPeriod,actor,search,sensitiveOnly,followSubject,resetFilters">
        {{-- Jenis data & periode --}}
        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-200/60 p-1" role="tablist" aria-label="Jenis data">
                @foreach (['' => 'Semua data'] + Changes::ENTITIES as $key => $label)
                    <button type="button" role="tab" wire:click="setEntity('{{ $key }}')" aria-selected="{{ $entity === $key ? 'true' : 'false' }}"
                            @class(['flex shrink-0 items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $entity === $key, 'text-stone-600 hover:text-stone-900' => $entity !== $key])>
                        {{ $label }}
                        <span class="rounded-full bg-stone-100 px-1.5 text-[11px] text-stone-500 tabular-nums">{{ $key === '' ? $s['total'] : $s['by_entity'][$key] }}</span>
                    </button>
                @endforeach
            </div>
            <div class="flex gap-1" role="group" aria-label="Periode">
                @foreach (\App\Services\AuditQuery::PERIODS as $key => $label)
                    <button type="button" wire:click="setPeriod('{{ $key }}')" aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                            @class(['rounded-full px-3 py-1 text-sm font-medium', 'bg-emerald-900 text-white' => $period === $key, 'border border-stone-300 bg-white text-stone-700 hover:border-stone-400' => $period !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>

        {{-- Ringkasan --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Perubahan tercatat</p>
                <p class="text-xl font-bold tabular-nums">{{ $s['total'] }}</p>
                <p class="text-xs text-stone-500">{{ count($s['actors']) }} petugas</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Harga jual diubah</p>
                <p class="text-xl font-bold tabular-nums">{{ $s['price_changes'] }}</p>
                <p class="text-xs text-stone-500">barang</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Dihapus · dipulihkan</p>
                <p class="text-xl font-bold tabular-nums">{{ $s['deleted'] }} · {{ $s['restored'] }}</p>
                <p class="text-xs text-stone-500">data</p>
            </div>
            <button type="button" wire:click="$toggle('sensitiveOnly')" aria-pressed="{{ $sensitiveOnly ? 'true' : 'false' }}"
                    @class(['rounded-xl border px-4 py-3 text-left transition', 'border-amber-500 bg-amber-50 ring-2 ring-amber-500/20' => $sensitiveOnly, 'border-amber-200 bg-amber-50/60 hover:border-amber-400' => ! $sensitiveOnly])>
                <p class="text-xs text-amber-900">Perlu ditinjau</p>
                <p class="text-xl font-bold text-amber-950 tabular-nums">{{ $s['sensitive'] }}</p>
                <p class="text-xs text-amber-800">{{ $sensitiveOnly ? 'Tampilkan semua' : 'Harga, status, pajak, hapus' }}</p>
            </button>
        </div>

        {{-- Saringan --}}
        <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
            <label class="relative flex-1">
                <span class="sr-only">Cari perubahan</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari kode/nama barang, anggota, petugas, alasan…"
                       class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
            <div class="flex flex-wrap gap-1" role="group" aria-label="Aksi">
                @foreach (Changes::ACTIONS as $key => $label)
                    <button type="button" wire:click="setAction('{{ $key }}')" aria-pressed="{{ $action === $key ? 'true' : 'false' }}"
                            @class(['rounded-full border px-3 py-1 text-sm font-medium', 'border-emerald-700 bg-emerald-800 text-white' => $action === $key, 'border-stone-300 bg-white text-stone-600 hover:border-stone-400' => $action !== $key])>{{ $label }}</button>
                @endforeach
            </div>
            <select wire:model.live="actor" aria-label="Petugas" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">Semua petugas</option>
                @foreach ($s['actors'] as $name => $n)
                    <option value="{{ $name }}">{{ $name }} ({{ $n }})</option>
                @endforeach
            </select>
            @if ($hasFilter)
                <button type="button" wire:click="resetFilters" class="px-2 text-sm font-medium text-stone-500 hover:text-stone-800">Reset</button>
            @endif
        </div>

        @if ($subject !== '')
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm">
                <p>Riwayat <span class="font-semibold">{{ $this->subjectLabel ?? $subject }}</span> · {{ count($filtered) }} perubahan</p>
                <button type="button" wire:click="$set('subject', '')" class="font-semibold text-emerald-800 hover:underline">Tutup riwayat objek</button>
            </div>
        @endif

        {{-- Daftar perubahan --}}
        <section class="space-y-4" aria-label="Daftar perubahan data">
            @forelse ($this->days as $date => $items)
                <div wire:key="chg-day-{{ $date }}">
                    <h2 class="mb-1.5 text-sm font-semibold">
                        {{ \Illuminate\Support\Carbon::parse($date)->isToday() ? 'Hari ini' : \Illuminate\Support\Carbon::parse($date)->translatedFormat('l') }}
                        <span class="font-normal text-stone-500">· {{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('d F Y') }}</span>
                    </h2>
                    <ol class="divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 bg-white">
                        @foreach ($items as $r)
                            @php $fields = array_keys($r['changes']); $sensitive = Changes::isSensitive($r); @endphp
                            <li wire:key="chg-{{ $r['id'] }}">
                                <button type="button" wire:click="show({{ $r['id'] }})" class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-emerald-50/50 focus-visible:bg-emerald-50 focus-visible:outline-none">
                                    <span class="mt-0.5 w-20 shrink-0">
                                        <span class="inline-block rounded-md px-2 py-0.5 text-xs font-semibold {{ $actionTone[$r['action']] }}">{{ Changes::ACTIONS[$r['action']] }}</span>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-center gap-x-2 text-sm">
                                            <span class="font-medium text-stone-900">{{ $r['subject_label'] }}</span>
                                            <span class="text-xs text-stone-500">{{ Changes::ENTITIES[$r['entity']] }}</span>
                                            @if ($sensitive)
                                                <span class="inline-flex items-center gap-1 rounded bg-amber-100 px-1.5 text-[11px] font-medium text-amber-900">
                                                    <svg class="size-3" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                                                    Perlu ditinjau
                                                </span>
                                            @endif
                                        </span>
                                        @if ($r['action'] === 'diubah' || $r['action'] === 'dipulihkan')
                                            <span class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                                                @foreach (array_slice($fields, 0, 3) as $f)
                                                    <span class="text-stone-600">{{ Changes::FIELDS[$f] ?? $f }}:
                                                        <span class="text-red-700 line-through decoration-red-300">{{ Changes::format($f, $r['changes'][$f]['from']) }}</span>
                                                        → <span class="font-semibold text-emerald-800">{{ Changes::format($f, $r['changes'][$f]['to']) }}</span></span>
                                                @endforeach
                                                @if (count($fields) > 3)<span class="text-stone-400">+{{ count($fields) - 3 }} kolom</span>@endif
                                            </span>
                                        @else
                                            <span class="mt-1 block text-xs text-stone-500">{{ count($fields) }} kolom {{ $r['action'] === 'dibuat' ? 'diisi' : 'tersimpan sebelum dihapus' }}</span>
                                        @endif
                                        <span class="mt-0.5 block text-xs text-stone-500">
                                            <span class="font-medium text-stone-700">{{ $r['actor_name'] }}</span> · {{ $roles[$r['actor_role']] ?? $r['actor_role'] }}
                                            @if ($r['reason']) · <span class="italic">“{{ $r['reason'] }}”</span>@endif
                                        </span>
                                    </span>
                                    <span class="shrink-0 text-xs text-stone-500 tabular-nums">{{ substr($r['at'], 11, 5) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center">
                    <p class="text-sm font-medium text-stone-600">Belum ada perubahan data pada saringan ini</p>
                    @if ($hasFilter || $period !== '30-hari')
                        <button type="button" wire:click="resetFilters" class="mt-2 text-sm font-medium text-emerald-700 hover:underline">Hapus saringan</button>
                    @endif
                </div>
            @endforelse

            @if (count($filtered) > $limit)
                <div class="text-center">
                    <button type="button" wire:click="more" class="rounded-xl border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                        Tampilkan lebih banyak <span class="font-normal text-stone-500">({{ count($filtered) - $limit }} lagi)</span>
                    </button>
                </div>
            @elseif ($filtered)
                <p class="text-center text-xs text-stone-400">{{ count($filtered) }} perubahan ditampilkan · riwayat tidak dapat diubah atau dihapus</p>
            @endif
        </section>
    </div>

    {{-- Panel rincian --}}
    @if ($r = $this->selected)
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="change-detail-title"
             x-data x-on:keydown.escape.window="$wire.closeDetail()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="closeDetail"></div>
            <div class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div>
                        <p class="text-xs text-stone-500">{{ Changes::ENTITIES[$r['entity']] }} · <span class="rounded px-1.5 font-semibold {{ $actionTone[$r['action']] }}">{{ Changes::ACTIONS[$r['action']] }}</span></p>
                        <h2 id="change-detail-title" class="mt-0.5 font-semibold">{{ $r['subject_label'] }}</h2>
                    </div>
                    <button type="button" wire:click="closeDetail" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 text-sm">
                    <dl class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2">
                        <dt class="text-stone-500">Waktu</dt><dd class="tabular-nums">{{ \Illuminate\Support\Carbon::parse($r['at'])->translatedFormat('l, d F Y · H:i') }}</dd>
                        <dt class="text-stone-500">Petugas</dt><dd>{{ $r['actor_name'] }} <span class="text-stone-500">· {{ $roles[$r['actor_role']] ?? $r['actor_role'] }}</span></dd>
                        <dt class="text-stone-500">Alasan</dt><dd>{{ $r['reason'] ?? '—' }}</dd>
                        <dt class="text-stone-500">Alamat IP</dt><dd class="font-mono text-xs">{{ $r['ip_address'] }}</dd>
                    </dl>

                    <section>
                        <h3 class="mb-2 font-semibold">{{ $r['action'] === 'dihapus' ? 'Data sebelum dihapus' : 'Perubahan' }}</h3>
                        <table class="w-full overflow-hidden rounded-lg border border-stone-200 text-xs">
                            <thead class="bg-stone-50 text-left text-stone-500"><tr><th class="px-3 py-1.5">Kolom</th><th class="px-3 py-1.5">Sebelum</th><th class="px-3 py-1.5">Sesudah</th></tr></thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($r['changes'] as $f => $c)
                                    <tr @class(['bg-amber-50/60' => in_array($f, Changes::SENSITIVE, true)])>
                                        <td class="px-3 py-1.5 font-medium">{{ Changes::FIELDS[$f] ?? $f }}</td>
                                        <td @class(['px-3 py-1.5', 'text-red-700 line-through decoration-red-300' => $c['from'] !== null, 'text-stone-400' => $c['from'] === null])>{{ Changes::format($f, $c['from']) }}</td>
                                        <td @class(['px-3 py-1.5', 'font-medium text-emerald-800' => $c['to'] !== null, 'text-stone-400' => $c['to'] === null])>{{ Changes::format($f, $c['to']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if (array_intersect(array_keys($r['changes']), Changes::SENSITIVE))
                            <p class="mt-1.5 text-xs text-amber-800">Baris berwarna adalah kolom sensitif (harga, status, batas diskon, pajak).</p>
                        @endif
                    </section>
                </div>

                <div class="border-t border-stone-200 px-5 py-3">
                    <button type="button" wire:click="followSubject('{{ $r['entity'] }}', {{ $r['subject_id'] }})"
                            class="w-full rounded-xl border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">Lihat semua riwayat {{ mb_strtolower(Changes::ENTITIES[$r['entity']]) }} ini</button>
                </div>
            </div>
        </div>
    @endif
</div>
