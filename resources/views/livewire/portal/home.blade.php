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
    <p class="text-sm text-stone-500">Terakhir masuk {{ $m->portal_last_login_at?->translatedFormat('d F Y H:i') ?? 'baru saja' }}.</p>
</div>
