@use('App\Livewire\Audit\Trash')
@php
    $s = $this->summary;
    $items = $this->items;
    $selectable = array_column(array_filter($items, fn ($r) => ! $r['conflict']), 'key');
    $allSelected = $selectable !== [] && array_diff($selectable, $selected) === [];
    $canRestore = $this->canRestore;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Audit & Riwayat</h1>
                <p class="text-xs text-stone-500">Data yang dihapus disimpan {{ $s['keep_days'] }} hari sebelum dibersihkan permanen. Selama itu masih dapat dipulihkan.</p>
            </div>
        </div>
        <x-audit.nav active="audit.terhapus" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6" wire:loading.class="opacity-60" wire:target="setEntity,search,restore">
        {{-- Ringkasan --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Data terhapus</p>
                <p class="text-xl font-bold tabular-nums">{{ $s['total'] }}</p>
                <p class="text-xs text-stone-500">{{ collect(Trash::ENTITIES)->map(fn ($l, $k) => $s['by_entity'][$k].' '.mb_strtolower($l))->implode(' · ') }}</p>
            </div>
            <div @class(['rounded-xl border px-4 py-3', 'border-red-200 bg-red-50' => $s['purge_soon'] > 0, 'border-stone-200 bg-white' => $s['purge_soon'] === 0])>
                <p class="text-xs text-stone-500">Segera dibersihkan</p>
                <p class="text-xl font-bold tabular-nums">{{ $s['purge_soon'] }}</p>
                <p class="text-xs text-stone-500">dalam ≤ {{ Trash::PURGE_SOON_DAYS }} hari</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Bentrok saat dipulihkan</p>
                <p class="text-xl font-bold tabular-nums">{{ $s['conflicts'] }}</p>
                <p class="text-xs text-stone-500">perlu dibereskan dulu</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                <p class="text-xs text-emerald-900">Masa simpan</p>
                <p class="text-xl font-bold text-emerald-950 tabular-nums">{{ $s['keep_days'] }} hari</p>
                <p class="text-xs text-emerald-800">{{ $canRestore ? 'Anda dapat memulihkan' : 'Pemulihan oleh Admin' }}</p>
            </div>
        </div>

        {{-- Saringan --}}
        <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
            <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-200/60 p-1" role="tablist" aria-label="Jenis data">
                @foreach (['' => 'Semua'] + Trash::ENTITIES as $key => $label)
                    <button type="button" role="tab" wire:click="setEntity('{{ $key }}')" aria-selected="{{ $entity === $key ? 'true' : 'false' }}"
                            @class(['flex shrink-0 items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $entity === $key, 'text-stone-600 hover:text-stone-900' => $entity !== $key])>
                        {{ $label }}
                        <span class="rounded-full bg-stone-100 px-1.5 text-[11px] text-stone-500 tabular-nums">{{ $key === '' ? $s['total'] : $s['by_entity'][$key] }}</span>
                    </button>
                @endforeach
            </div>
            <label class="relative flex-1">
                <span class="sr-only">Cari data terhapus</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari kode, nama, penghapus, alasan…"
                       class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
        </div>

        {{-- Bilah aksi terpilih --}}
        @if ($canRestore && $selected)
            <div class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-emerald-950 px-4 py-2.5 text-sm text-white shadow">
                <p>{{ count($selected) }} data dipilih</p>
                <div class="flex gap-2">
                    <button type="button" wire:click="$set('selected', [])" class="rounded-lg px-3 py-1.5 font-medium text-emerald-200 hover:text-white">Batal pilih</button>
                    <button type="button" wire:click="confirmRestore" class="rounded-lg bg-amber-400 px-3 py-1.5 font-semibold text-amber-950 hover:bg-amber-300">Pulihkan terpilih</button>
                </div>
            </div>
        @endif

        {{-- Daftar --}}
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar data terhapus">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            @if ($canRestore)
                                <th class="w-10 px-4 py-2">
                                    <input type="checkbox" wire:click="toggleAll" @checked($allSelected) @disabled($selectable === []) aria-label="Pilih semua yang dapat dipulihkan" class="size-4 rounded border-stone-300 text-emerald-700">
                                </th>
                            @endif
                            <th @class(['py-2 font-medium', 'px-4' => ! $canRestore, 'px-2' => $canRestore])>Data</th>
                            <th class="px-3 py-2 font-medium">Dihapus</th>
                            <th class="px-3 py-2 font-medium">Alasan</th>
                            <th class="px-3 py-2 font-medium">Dibersihkan</th>
                            <th class="px-4 py-2"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($items as $r)
                            @php $left = Trash::daysLeft($r); @endphp
                            <tr wire:key="trash-{{ $r['key'] }}" @class(['align-top', 'bg-emerald-50/50' => in_array($r['key'], $selected, true)])>
                                @if ($canRestore)
                                    <td class="px-4 py-3">
                                        <input type="checkbox" wire:model.live="selected" value="{{ $r['key'] }}" @disabled($r['conflict']) aria-label="Pilih {{ $r['label'] }}" class="size-4 rounded border-stone-300 text-emerald-700 disabled:opacity-40">
                                    </td>
                                @endif
                                <td @class(['py-3', 'px-4' => ! $canRestore, 'px-2' => $canRestore])>
                                    <p class="font-medium">{{ $r['label'] }}</p>
                                    <p class="text-xs text-stone-500"><span class="font-medium text-stone-600">{{ Trash::ENTITIES[$r['entity']] }}</span> · {{ $r['detail'] }}</p>
                                    @if ($r['conflict'])
                                        <p class="mt-1 flex max-w-md items-start gap-1 text-xs text-amber-800">
                                            <svg class="mt-px size-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                                            {{ $r['conflict'] }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap">
                                    <p class="tabular-nums">{{ \Illuminate\Support\Carbon::parse($r['deleted_at'])->translatedFormat('d M Y · H:i') }}</p>
                                    <p class="text-xs text-stone-500">oleh {{ $r['deleted_by'] ?? 'Sistem' }}</p>
                                </td>
                                <td class="max-w-56 px-3 py-3 text-xs text-stone-600">{{ $r['reason'] ?? '—' }}</td>
                                <td class="px-3 py-3 whitespace-nowrap">
                                    <span @class(['rounded-md px-2 py-0.5 text-xs font-semibold tabular-nums', 'bg-red-100 text-red-800' => $left <= Trash::PURGE_SOON_DAYS, 'bg-stone-100 text-stone-600' => $left > Trash::PURGE_SOON_DAYS])>
                                        {{ $left === 0 ? 'hari ini' : $left.' hari lagi' }}
                                    </span>
                                    <p class="mt-0.5 text-xs text-stone-500 tabular-nums">{{ \Illuminate\Support\Carbon::parse($r['purge_at'])->translatedFormat('d M Y') }}</p>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($canRestore)
                                        <button type="button" wire:click="confirmRestore('{{ $r['key'] }}')" @disabled($r['conflict'])
                                                class="rounded-lg border border-emerald-700 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:border-stone-200 disabled:text-stone-400 disabled:hover:bg-transparent">Pulihkan</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-12 text-center text-stone-400">{{ $search !== '' || $entity !== '' ? 'Tidak ada data terhapus yang cocok.' : 'Tidak ada data terhapus. Semua data masih aktif.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @unless ($canRestore)
            <p class="text-xs text-stone-500">Anda dapat melihat data terhapus. Pemulihan hanya dapat dilakukan Admin.</p>
        @endunless
    </div>

    {{-- Konfirmasi pulihkan --}}
    @if ($pending = $this->pending)
        <div class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="restore-title"
             x-data x-on:keydown.escape.window="$wire.cancelRestore()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="cancelRestore"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
                <h2 id="restore-title" class="font-semibold">{{ count($pending) === 1 ? 'Pulihkan data ini?' : 'Pulihkan '.count($pending).' data?' }}</h2>
                <ul class="mt-3 max-h-48 divide-y divide-stone-100 overflow-y-auto rounded-lg border border-stone-200 text-sm">
                    @foreach ($pending as $p)
                        <li class="px-3 py-2"><span class="font-medium">{{ $p['label'] }}</span> <span class="text-xs text-stone-500">· {{ Trash::ENTITIES[$p['entity']] }}</span></li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-stone-600">Data akan kembali aktif dan muncul lagi di daftar {{ collect($pending)->pluck('entity')->unique()->map(fn ($e) => mb_strtolower(Trash::ENTITIES[$e]))->implode(' & ') }}. Pemulihan tercatat di audit.</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="cancelRestore" class="rounded-xl px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100">Batal</button>
                    <button type="button" wire:click="restore" wire:loading.attr="disabled" wire:target="restore" class="rounded-xl bg-emerald-800 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-900">Pulihkan</button>
                </div>
            </div>
        </div>
    @endif
</div>
