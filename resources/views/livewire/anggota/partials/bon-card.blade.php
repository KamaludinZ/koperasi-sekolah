{{-- Bon (piutang) anggota: sisa terhadap batas, daftar bon belum lunas, tombol pelunasan. --}}
@php $cr = $this->credit; @endphp
<section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="bon-title">
    <div class="flex items-start justify-between gap-2">
        <div>
            <h2 id="bon-title" class="text-sm text-stone-500">Bon belum lunas</h2>
            <p @class(['mt-1 text-2xl font-bold tabular-nums', 'text-red-700' => $cr['overdue'], 'text-stone-900' => ! $cr['overdue']])>{{ $rp($cr['outstanding']) }}</p>
        </div>
        <span class="rounded bg-stone-100 px-1.5 py-0.5 text-[10px] font-medium text-stone-500" title="Menunggu backend bon">data contoh</span>
    </div>
    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-stone-100" aria-hidden="true">
        <span @class(['block h-full rounded-full', 'bg-red-500' => $cr['overdue'], 'bg-amber-500' => ! $cr['overdue']]) style="width: {{ $cr['limit'] ? min(100, (int) round($cr['outstanding'] / $cr['limit'] * 100)) : 0 }}%"></span>
    </div>
    <p class="mt-1 text-xs text-stone-500">Sisa batas {{ $rp($cr['available']) }} dari {{ $rp($cr['limit']) }}</p>

    @if ($bills)
        <ul class="mt-3 divide-y divide-stone-100 text-sm">
            @foreach ($bills as $b)
                <li wire:key="bon-{{ $b['id'] }}" class="flex items-start justify-between gap-2 py-2">
                    <span class="min-w-0">
                        <span class="block font-mono text-xs font-semibold">{{ $b['number'] }}</span>
                        <span @class(['block text-xs', 'font-semibold text-red-700' => $b['overdue'], 'text-stone-500' => ! $b['overdue']])>
                            {{ $b['overdue'] ? 'Lewat jatuh tempo' : 'Jatuh tempo' }} {{ \Illuminate\Support\Carbon::parse($b['due_at'])->translatedFormat('d M Y') }}
                        </span>
                    </span>
                    <span class="shrink-0 text-right tabular-nums">
                        {{ $rp($b['remaining']) }}
                        @if ($b['remaining'] < $b['amount'])<span class="block text-xs text-stone-500">dari {{ $rp($b['amount']) }}</span>@endif
                    </span>
                </li>
            @endforeach
        </ul>
        <button type="button" wire:click="openRepay" class="mt-3 w-full rounded-xl bg-emerald-700 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Lunasi bon</button>
    @else
        <p class="mt-3 text-sm text-stone-500">Tidak ada bon yang belum lunas.</p>
    @endif

    @if ($lastRepayment)
        <p class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900" role="status">
            Pelunasan {{ $rp($lastRepayment['amount']) }} ({{ mb_strtolower(\App\Livewire\Anggota\Show::REPAY_METHODS[$lastRepayment['method']]) }}) tercatat{{ $lastRepayment['settled'] ? ', '.$lastRepayment['settled'].' bon lunas' : '' }}.
            {{ $lastRepayment['remaining'] ? 'Sisa bon '.$rp($lastRepayment['remaining']).'.' : 'Semua bon lunas.' }}
        </p>
    @endif
</section>
