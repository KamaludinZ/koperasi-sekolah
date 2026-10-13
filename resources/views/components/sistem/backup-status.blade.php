{{-- Panel status cadangan: strip malam terakhir, rangkaian berhasil, umur cadangan terakhir, kegagalan terbaru + saran.
     Butuh: $status (BackupStatus::summarize). `retry` = nama aksi Livewire untuk "Coba lagi" (opsional). --}}
@props(['status', 'retry' => null, 'compact' => false])
@php
    $s = $status;
    $tone = ['ok' => 'border-emerald-200', 'peringatan' => 'border-amber-300', 'gangguan' => 'border-red-300'][$s['status']];
    $title = ['ok' => 'Cadangan berjalan baik', 'peringatan' => 'Cadangan perlu diperhatikan', 'gangguan' => 'Cadangan bermasalah'][$s['status']];
    $dt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y, H:i');
@endphp
<section {{ $attributes->class(['rounded-2xl border bg-white p-5', $tone]) }} aria-labelledby="bks-title" data-backup-status="{{ $s['status'] }}">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 id="bks-title" class="font-semibold">{{ $title }}</h2>
            <p class="text-xs text-stone-500">
                @if ($s['last_ok'])
                    Terakhir berhasil {{ \Illuminate\Support\Carbon::parse($s['last_ok']['created_at'])->diffForHumans() }}
                    @if ($s['age_hours'] >= \App\Support\BackupStatus::STALE_HOURS) — <span class="font-semibold text-amber-800">lebih dari sehari tanpa cadangan</span>@endif
                @else
                    Belum ada cadangan yang berhasil.
                @endif
            </p>
        </div>
        <p class="text-sm tabular-nums"><span class="font-semibold">{{ $s['streak'] }}</span> malam berturut-turut berhasil</p>
    </div>

    <ol class="mt-3 flex gap-1" aria-label="Cadangan {{ count($s['strip']) }} malam terakhir">
        @foreach ($s['strip'] as $d)
            <li class="flex-1" title="{{ \Illuminate\Support\Carbon::parse($d['date'])->translatedFormat('D, d M') }}: {{ ['berhasil' => 'berhasil', 'gagal' => 'gagal', 'kosong' => 'tidak ada cadangan'][$d['status']] }}">
                <span @class(['block h-6 rounded', 'bg-emerald-500' => $d['status'] === 'berhasil', 'bg-red-500' => $d['status'] === 'gagal', 'bg-stone-200' => $d['status'] === 'kosong'])></span>
                @unless ($compact)<span class="mt-1 block text-center text-[10px] text-stone-400">{{ \Illuminate\Support\Carbon::parse($d['date'])->format('d') }}</span>@endunless
                <span class="sr-only">{{ $d['date'] }} {{ $d['status'] }}</span>
            </li>
        @endforeach
    </ol>
    <p class="mt-1 flex gap-3 text-[11px] text-stone-500"><span><span class="mr-1 inline-block size-2 rounded-sm bg-emerald-500"></span>berhasil</span><span><span class="mr-1 inline-block size-2 rounded-sm bg-red-500"></span>gagal</span><span><span class="mr-1 inline-block size-2 rounded-sm bg-stone-200"></span>tidak ada</span></p>

    @if ($f = $s['last_failure'])
        <div @class(['mt-4 rounded-xl px-4 py-3 text-sm', 'bg-red-50 text-red-900' => $f['unresolved'], 'bg-stone-50 text-stone-700' => ! $f['unresolved']])>
            <p class="font-semibold">{{ $f['unresolved'] ? 'Cadangan terakhir gagal' : 'Kegagalan terakhir (sudah tertangani)' }} · {{ $dt($f['created_at']) }}</p>
            @if ($f['note'])<p>{{ $f['note'] }}</p>@endif
            <p class="mt-1 text-xs"><span class="font-medium">Saran:</span> {{ $f['advice'] }}</p>
            @if ($retry && $f['unresolved'])
                <button type="button" wire:click="{{ $retry }}" class="mt-2 rounded-lg bg-red-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-800">Coba cadangkan lagi</button>
            @endif
        </div>
    @endif
</section>
