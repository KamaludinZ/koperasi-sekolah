@use('App\Livewire\Sistem\Backups')
@use('App\Support\SystemHealth')
@php
    $s = $this->stats;
    $sch = $this->schedule;
    $dt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y, H:i');
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto" @if (collect($this->all)->contains('status', 'berjalan')) wire:poll.5s @endif>
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Sistem</h1>
                <p class="text-xs text-stone-500">Cadangan basis data dan berkas koperasi, dibuat otomatis tiap malam.</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="openCreate" class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Cadangkan sekarang</button>
            </div>
        </div>
        <x-sistem.nav active="sistem.cadangan" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        <x-sistem.backup-status :status="$this->status" retry="retry" />

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                <p class="text-xs text-emerald-800">Cadangan terakhir berhasil</p>
                <p class="text-lg font-bold text-emerald-900">{{ $s['last'] ? \Illuminate\Support\Carbon::parse($s['last']['created_at'])->diffForHumans() : '—' }}</p>
                @if ($s['last'])<p class="text-xs text-emerald-800">{{ $dt($s['last']['created_at']) }}</p>@endif
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Jadwal berikutnya</p>
                <p class="text-lg font-bold">{{ $sch['enabled'] ? \Illuminate\Support\Carbon::parse($s['next_at'])->translatedFormat('D, H:i') : 'Nonaktif' }}</p>
                <p class="text-xs text-stone-500">{{ ucfirst($sch['frequency']) }} pukul {{ $sch['time'] }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Tersimpan</p>
                <p class="text-lg font-bold tabular-nums">{{ $s['count'] }} cadangan</p>
                <p class="text-xs text-stone-500">{{ SystemHealth::bytes($s['total_size']) }} · simpan {{ $sch['keep'] }} terakhir</p>
            </div>
            <div @class(['rounded-xl border px-4 py-3', 'border-red-200 bg-red-50' => $s['failed_30d'], 'border-stone-200 bg-white' => ! $s['failed_30d']])>
                <p @class(['text-xs', 'text-red-800' => $s['failed_30d'], 'text-stone-500' => ! $s['failed_30d']])>Gagal 30 hari</p>
                <p @class(['text-2xl font-bold tabular-nums', 'text-red-800' => $s['failed_30d']])>{{ $s['failed_30d'] }}</p>
            </div>
        </div>

        <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-200/60 p-1" role="tablist" aria-label="Saring cadangan">
            @foreach (Backups::FILTERS as $key => $label)
                <button type="button" role="tab" wire:click="setFilter('{{ $key }}')" aria-selected="{{ $filter === $key ? 'true' : 'false' }}"
                        @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $filter === $key, 'text-stone-600 hover:text-stone-900' => $filter !== $key])>{{ $label }}</button>
            @endforeach
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar cadangan">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[46rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr><th class="px-4 py-2 font-medium">Waktu</th><th class="px-3 py-2 font-medium">Isi</th><th class="px-3 py-2 font-medium">Pemicu</th><th class="px-3 py-2 font-medium">Status</th><th class="px-3 py-2 text-right font-medium">Ukuran</th><th class="px-4 py-2 text-right font-medium">Durasi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->rows as $b)
                            <tr wire:key="bk-{{ $b['id'] }}" class="cursor-pointer hover:bg-stone-50" wire:click="show({{ $b['id'] }})">
                                <td class="px-4 py-2.5"><span class="font-medium">{{ $dt($b['created_at']) }}</span><span class="block font-mono text-[11px] text-stone-500">{{ $b['name'] }}</span></td>
                                <td class="px-3 py-2.5 text-stone-700">{{ Backups::TYPES[$b['type']] ?? $b['type'] }}</td>
                                <td class="px-3 py-2.5 text-stone-600">{{ $b['trigger'] === 'manual' ? 'Manual'.($b['by'] ? ' · '.$b['by'] : '') : 'Terjadwal' }}</td>
                                <td class="px-3 py-2.5"><span @class(['rounded-md px-2 py-0.5 text-[11px] font-semibold', 'bg-emerald-100 text-emerald-800' => $b['status'] === 'berhasil', 'bg-red-100 text-red-800' => $b['status'] === 'gagal', 'bg-amber-100 text-amber-900' => $b['status'] === 'berjalan'])>{{ ucfirst($b['status']) }}</span></td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $b['size'] ? SystemHealth::bytes($b['size']) : '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-stone-600">{{ $b['status'] === 'berjalan' ? '…' : $b['duration_s'].' dtk' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-stone-400">Tidak ada cadangan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <p class="text-xs text-stone-500">Cadangan disimpan di server ({{ $sch['disk'] }}). Simpan salinan berkala di tempat lain (flashdisk/awan) agar aman bila server rusak.</p>
    </div>

    @if ($creating)
        <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="bk-new" x-data x-on:keydown.escape.window="$wire.set('creating', false)">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="$set('creating', false)"></div>
            <form wire:submit="create" class="relative w-full max-w-md space-y-4 rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                <h2 id="bk-new" class="font-semibold">Cadangkan sekarang</h2>
                <fieldset class="space-y-2 text-sm">
                    @foreach (Backups::TYPES as $k => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-stone-300 px-3 py-2 has-checked:border-emerald-600 has-checked:bg-emerald-50">
                            <input type="radio" wire:model="newType" value="{{ $k }}" class="accent-emerald-700" @checked($newType === $k)> {{ $label }}
                        </label>
                    @endforeach
                    @error('newType') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </fieldset>
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Catatan <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="text" wire:model="newNote" maxlength="120" placeholder="mis. Sebelum tutup buku semester" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    @error('newNote') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <p class="text-xs text-stone-500">Proses berjalan di latar dan tidak mengganggu kasir.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="$set('creating', false)" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                    <button type="submit" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Mulai cadangkan</button>
                </div>
            </form>
        </div>
    @endif

    @if ($b = $this->selected)
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="bk-detail" x-data x-on:keydown.escape.window="$wire.closeDetail()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="closeDetail"></div>
            <div class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div><p class="text-xs text-stone-500">{{ $dt($b['created_at']) }}</p><h2 id="bk-detail" class="font-mono text-sm font-semibold">{{ $b['name'] }}</h2></div>
                    <button type="button" wire:click="closeDetail" class="text-stone-400 hover:text-stone-600" aria-label="Tutup"><svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
                </div>
                <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-2 px-5 py-5 text-sm">
                    <dt class="text-stone-500">Status</dt><dd>{{ ucfirst($b['status']) }}@if ($b['note'] && $b['status'] === 'gagal')<span class="block text-xs text-red-700">{{ $b['note'] }}</span>@endif</dd>
                    <dt class="text-stone-500">Isi</dt><dd>{{ $b['includes'] ? implode(', ', $b['includes']) : '—' }}</dd>
                    <dt class="text-stone-500">Ukuran</dt><dd>{{ $b['size'] ? SystemHealth::bytes($b['size']) : '—' }}</dd>
                    <dt class="text-stone-500">Durasi</dt><dd>{{ $b['duration_s'] }} detik</dd>
                    <dt class="text-stone-500">Pemicu</dt><dd>{{ $b['trigger'] === 'manual' ? 'Manual oleh '.($b['by'] ?? '—') : 'Jadwal otomatis' }}</dd>
                    @if ($b['note'] && $b['status'] !== 'gagal')<dt class="text-stone-500">Catatan</dt><dd>{{ $b['note'] }}</dd>@endif
                    <dt class="text-stone-500">Checksum</dt><dd class="font-mono text-xs">{{ $b['checksum'] ?? '—' }}</dd>
                </dl>
                @if ($b['status'] === 'berhasil')
                    <div class="mt-auto space-y-2 border-t border-stone-200 px-5 py-4">
                        <a href="{{ route('sistem.cadangan.unduh', $b['id']) }}" class="block rounded-xl border border-stone-300 px-4 py-2 text-center text-sm font-semibold text-stone-700 hover:bg-stone-50">Unduh cadangan</a>
                        <a href="{{ route('sistem.restore', ['cadangan' => $b['id']]) }}" wire:navigate class="block rounded-xl border border-amber-300 bg-amber-50 px-4 py-2 text-center text-sm font-semibold text-amber-900 hover:bg-amber-100">Uji pemulihan dari cadangan ini</a>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
