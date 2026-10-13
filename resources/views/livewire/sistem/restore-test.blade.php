@use('App\Livewire\Sistem\RestoreTest')
@use('App\Support\SystemHealth')
@php
    $dt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y, H:i');
    $b = $this->backup;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Sistem</h1>
                <p class="text-xs text-stone-500">Pastikan cadangan benar-benar bisa dipulihkan, tanpa menyentuh data asli.</p>
            </div>
        </div>
        <x-sistem.nav active="sistem.restore" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        @if ($this->due)
            <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">Sudah lebih dari {{ RestoreTest::INTERVAL_DAYS }} hari sejak uji pemulihan terakhir yang berhasil. Jalankan uji sekarang.</p>
        @endif

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="space-y-4 rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="rt-run">
                <div>
                    <h2 id="rt-run" class="font-semibold">Jalankan uji pemulihan</h2>
                    <p class="text-xs text-stone-500">Cadangan dipulihkan ke basis data uji sementara, dihitung, dibandingkan, lalu dihapus. Kasir tetap bisa bekerja.</p>
                </div>
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Cadangan</span>
                    <select wire:model.live="backupId" class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                        @foreach ($this->backups as $opt)
                            <option value="{{ $opt['id'] }}">{{ $dt($opt['created_at']) }} · {{ SystemHealth::bytes($opt['size']) }} · {{ $opt['trigger'] === 'manual' ? 'manual' : 'terjadwal' }}</option>
                        @endforeach
                    </select>
                    @error('backupId') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                @if ($b)
                    <p class="text-xs text-stone-500">Isi: {{ implode(', ', $b['includes']) }} · checksum <span class="font-mono">{{ $b['checksum'] }}</span></p>
                @endif
                <label class="flex cursor-pointer items-start gap-2 text-sm">
                    <input type="checkbox" wire:model="confirm" class="mt-0.5 size-4 accent-emerald-700">
                    <span>Saya paham uji ini memakai basis data uji terpisah dan <strong>tidak mengubah data koperasi</strong>.</span>
                </label>
                @error('confirm') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                <button type="button" wire:click="run" wire:loading.attr="disabled" wire:target="run" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
                    <span wire:loading.remove wire:target="run">Mulai uji pemulihan</span><span wire:loading wire:target="run">Menguji…</span>
                </button>

                @if ($result)
                    <div class="space-y-3 border-t border-stone-100 pt-4" data-restore-result>
                        @if ($result['status'] === 'berhasil')
                            <p class="font-semibold text-emerald-800">Berhasil dipulihkan dalam {{ $result['duration_s'] }} detik.</p>
                        @elseif ($result['status'] === 'gagal')
                            <p class="font-semibold text-red-700">Uji gagal: {{ $result['error'] }}</p>
                        @else
                            <p class="font-semibold text-amber-800">Uji sedang berjalan di latar — lihat riwayat sebentar lagi.</p>
                        @endif
                        @if ($result['steps'])
                            <ol class="space-y-1 text-sm">
                                @foreach ($result['steps'] as $step)
                                    <li class="flex items-center gap-2"><span @class(['grid size-5 place-items-center rounded-full text-[11px] font-bold', 'bg-emerald-100 text-emerald-800' => $step['ok'], 'bg-red-100 text-red-800' => ! $step['ok']])>{{ $step['ok'] ? '✓' : '!' }}</span>{{ $step['label'] }}</li>
                                @endforeach
                            </ol>
                        @endif
                        @if ($result['tables'])
                            <div class="max-h-80 overflow-y-auto">
                            <table class="w-full text-sm">
                                <thead class="text-left text-xs text-stone-500"><tr><th class="py-1 font-medium">Data</th><th class="py-1 text-right font-medium">Di cadangan</th><th class="py-1 text-right font-medium">Dipulihkan</th><th class="py-1 text-right font-medium">Sekarang</th></tr></thead>
                                <tbody class="divide-y divide-stone-100">
                                    @foreach ($result['tables'] as $t)
                                        <tr @class(['text-red-700' => ! ($t['ok'] ?? true)])><td class="py-1.5">{{ $t['label'] }}</td><td class="py-1.5 text-right tabular-nums">{{ number_format($t['backup'], 0, ',', '.') }}</td><td class="py-1.5 text-right tabular-nums">{{ number_format($t['restored'] ?? $t['backup'], 0, ',', '.') }}</td><td class="py-1.5 text-right tabular-nums">{{ number_format($t['live'], 0, ',', '.') }}@if ($t['newer'] > 0)<span class="text-xs text-stone-500"> (+{{ number_format($t['newer'], 0, ',', '.') }})</span>@endif</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                            <p class="text-xs text-stone-500">Selisih wajar = data yang dibuat setelah cadangan. Bila dipulihkan, data sejak {{ $dt($result['backup_at']) }} hilang.</p>
                        @endif
                    </div>
                @endif
            </section>

            <aside class="h-fit rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="rt-hist">
                <h2 id="rt-hist" class="font-semibold">Riwayat uji</h2>
                <ul class="mt-3 divide-y divide-stone-100 text-sm">
                    @forelse ($this->history as $h)
                        <li wire:key="rh-{{ $h['id'] }}" class="py-2.5">
                            <p class="flex items-center justify-between gap-2">
                                <span>{{ $dt($h['started_at']) }}</span>
                                <span @class(['rounded-md px-2 py-0.5 text-[11px] font-semibold', 'bg-emerald-100 text-emerald-800' => $h['status'] === 'berhasil', 'bg-red-100 text-red-800' => $h['status'] === 'gagal'])>{{ ucfirst($h['status']) }}</span>
                            </p>
                            <p class="text-xs text-stone-500">Cadangan {{ $dt($h['backup_at']) }} · {{ $h['duration_s'] }} dtk · {{ $h['by'] }}</p>
                            @if ($h['note'])<p class="text-xs text-red-700">{{ $h['note'] }}</p>@endif
                        </li>
                    @empty
                        <li class="py-6 text-center text-sm text-stone-400">Belum pernah diuji.</li>
                    @endforelse
                </ul>
            </aside>
        </div>
    </div>
</div>
