{{-- Simpanan per jenis (pokok/wajib/sukarela) — terpisah dari saldo tabungan. Butuh: $sv (MemberSavingService::summary). --}}
@php $rp = fn ($n) => \App\Support\Rupiah::format($n); @endphp
<section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sv-title" data-saving-types>
    <div class="flex items-baseline justify-between gap-2">
        <h2 id="sv-title" class="font-semibold">Simpanan</h2>
        <p class="text-sm text-stone-500">Total <strong class="text-stone-900 tabular-nums">{{ $rp($sv['total']) }}</strong></p>
    </div>
    @if ($sv['types'])
        <ul class="mt-3 divide-y divide-stone-100">
            @foreach ($sv['types'] as $t)
                <li class="py-2.5" wire:key="sv-{{ $t['id'] }}">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="min-w-0 text-sm font-medium">{{ $t['name'] }}@unless ($t['is_active']) <span class="text-xs font-normal text-stone-400">(nonaktif)</span>@endunless</p>
                        <p class="shrink-0 font-semibold tabular-nums">{{ $rp($t['balance']) }}</p>
                    </div>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-stone-500">
                        @if ($t['status'] === 'lunas')
                            <span class="rounded bg-emerald-100 px-1.5 py-0.5 font-medium text-emerald-800">{{ $t['period'] === 'bulanan' ? 'Lancar' : 'Lunas' }}</span>
                        @elseif ($t['status'] === 'belum')
                            <span class="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-900">Belum dibayar {{ $rp($t['amount']) }}</span>
                        @elseif ($t['status'] === 'tunggak')
                            <span class="rounded bg-red-100 px-1.5 py-0.5 font-medium text-red-800">Tunggakan {{ $t['arrears_months'] }} bulan · {{ $rp($t['arrears_amount']) }}</span>
                        @endif
                        <span>{{ $t['withdrawable'] ? 'Boleh ditarik' : 'Ditahan selama anggota' }}</span>
                        @if ($t['last_at'])<span>· mutasi {{ \Illuminate\Support\Carbon::parse($t['last_at'])->diffForHumans() }}</span>@endif
                    </p>
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-2 text-sm text-stone-500">Belum ada jenis simpanan aktif.</p>
    @endif
    @if (\Illuminate\Support\Facades\Route::has('simpanan'))
        <a href="{{ route('simpanan') }}" wire:navigate class="mt-2 inline-block text-xs font-medium text-emerald-800 hover:underline">Kelola jenis simpanan →</a>
    @endif
</section>
