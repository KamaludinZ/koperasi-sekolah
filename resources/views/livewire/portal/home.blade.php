@php $m = $this->member; @endphp
<div class="space-y-5">
    <section class="rounded-2xl bg-emerald-900 p-5 text-white">
        <p class="text-sm text-emerald-200">Assalamu'alaikum,</p>
        <h1 class="text-2xl font-semibold">{{ $m->name }}</h1>
        <p class="mt-1 text-sm text-emerald-200"><span class="font-mono">{{ $m->member_code }}</span>{{ $m->class ? ' · Kelas '.$m->class : '' }}</p>
        <div class="mt-4 rounded-xl bg-white/10 px-4 py-3">
            <p class="text-xs text-emerald-200">Saldo tabungan</p>
            <p class="text-2xl font-bold tabular-nums">{{ \App\Support\Rupiah::format((int) round((float) $m->balance)) }}</p>
        </div>
    </section>
    @if ($this->loanTotals)
        <x-portal.loan-alert :totals="$this->loanTotals" />
    @endif
    @php $open = \App\Support\OpeningHours::status(\App\Support\MockData::profile()['schedule'], now()); @endphp
    <p @class(['flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-900' => $open['open'], 'bg-stone-100 text-stone-700' => ! $open['open']]) data-open-status="{{ $open['open'] ? 'buka' : 'tutup' }}">
        <span @class(['size-2 rounded-full', 'bg-emerald-600' => $open['open'], 'bg-stone-500' => ! $open['open']])></span>
        Koperasi {{ mb_strtolower($open['label']) }}@if ($open['detail']) · {{ $open['detail'] }}@endif
    </p>
    @if (\Illuminate\Support\Facades\Route::has('portal.simpanan'))
        <a href="{{ route('portal.simpanan') }}" wire:navigate class="flex items-center justify-between rounded-2xl border border-stone-200 bg-white px-4 py-3 hover:border-emerald-600">
            <span><span class="block font-medium">Simpanan & tabungan</span><span class="text-sm text-stone-500">Saldo per jenis dan riwayat mutasi</span></span>
            <span aria-hidden="true" class="text-stone-400">→</span>
        </a>
    @endif
    <p class="text-sm text-stone-500">Terakhir masuk {{ $m->portal_last_login_at?->translatedFormat('d F Y H:i') ?? 'baru saja' }}.</p>
</div>
