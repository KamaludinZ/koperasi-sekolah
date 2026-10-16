{{-- Status & riwayat sinkronisasi panduan (guide_sync_jobs) --}}
@php
    $badge = fn (string $s) => match ($s) {
        'done' => 'bg-emerald-100 text-emerald-800', 'failed' => 'bg-red-100 text-red-800',
        'running' => 'bg-sky-100 text-sky-800', default => 'bg-stone-100 text-stone-700',
    };
@endphp
@php $active = $runs['rows']->whereIn('status', ['queued', 'running'])->isNotEmpty(); @endphp
<div class="space-y-4 px-4 py-5 sm:px-6" data-guide-sync-runs @if ($active) wire:poll.5s data-sync-polling @endif>
    @if ($active)
        <p class="rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-900 ring-1 ring-sky-200" role="status">Sinkronisasi sedang mengantre/berjalan — halaman ini diperbarui otomatis.</p>
    @endif
    <div class="flex flex-wrap gap-1.5 text-xs" role="group" aria-label="Saring status">
        <button type="button" wire:click="$set('runStatus', '')" aria-pressed="{{ $runStatus === '' ? 'true' : 'false' }}" data-run-filter=""
                @class(['rounded-full px-3 py-1 font-medium', 'bg-emerald-700 text-white' => $runStatus === '', 'border border-stone-300 bg-white text-stone-700 hover:bg-stone-50' => $runStatus !== ''])>Semua ({{ array_sum($runs['counts']) }})</button>
        @foreach (\App\Models\GuideSyncJob::STATUSES as $s => $label)
            <button type="button" wire:click="$set('runStatus', '{{ $s }}')" aria-pressed="{{ $runStatus === $s ? 'true' : 'false' }}" data-run-filter="{{ $s }}"
                    @class(['rounded-full px-3 py-1 font-medium', 'bg-emerald-700 text-white' => $runStatus === $s, 'border border-stone-300 bg-white text-stone-700 hover:bg-stone-50' => $runStatus !== $s])>{{ $label }} ({{ $runs['counts'][$s] ?? 0 }})</button>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white">
        <table class="w-full min-w-[44rem] text-sm">
            <thead class="text-left text-xs text-stone-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Waktu</th><th class="px-3 py-2 font-medium">Status</th><th class="px-3 py-2 font-medium">Pemicu</th>
                    <th class="px-3 py-2 font-medium">Cakupan</th><th class="px-3 py-2 font-medium">Hasil</th><th class="px-3 py-2 font-medium">Lama</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($runs['rows'] as $run)
                    <tr wire:key="run-{{ $run->id }}" data-sync-run="{{ $run->id }}" data-run-status="{{ $run->status }}">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $run->created_at?->translatedFormat('j M Y H:i') }}<span class="block text-xs text-stone-500">{{ $run->user?->name ?? 'Sistem' }}</span></td>
                        <td class="px-3 py-2"><span class="rounded px-1.5 py-0.5 text-[11px] font-semibold {{ $badge($run->status) }}">{{ \App\Models\GuideSyncJob::STATUSES[$run->status] ?? $run->status }}</span></td>
                        <td class="px-3 py-2">{{ \App\Models\GuideSyncJob::TRIGGERS[$run->trigger] ?? $run->trigger }} @if ($run->note)<span class="block text-xs text-stone-500">{{ $run->note }}</span>@endif</td>
                        <td class="px-3 py-2 text-xs">{{ $run->scope ? collect($run->scope)->map(fn ($k) => $this->titleOf($k))->join(', ') : 'Semua panduan' }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if ($run->status === 'failed')
                                <span class="text-red-800" data-run-error>{{ $run->error }}</span>
                            @elseif ($run->stats)
                                {{ $run->stats['created'] ?? 0 }} baru · {{ $run->stats['updated'] ?? 0 }} diperbarui · {{ $run->stats['unchanged'] ?? 0 }} tetap{{ ($run->stats['skipped_manual'] ?? 0) > 0 ? ' · '.$run->stats['skipped_manual'].' manual' : '' }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs tabular-nums">{{ ($d = $run->durationSeconds()) !== null ? $d.' dtk' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-stone-500" data-runs-empty>Belum ada sinkronisasi{{ $runStatus !== '' ? ' dengan status ini' : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-xs text-stone-500">Menampilkan 50 proses terbaru.</p>
</div>
