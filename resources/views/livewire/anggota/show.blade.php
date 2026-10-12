@php
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    $m = $profile;
    $initials = collect(explode(' ', preg_replace('/^(Pak|Bu)\s+/', '', $m['name'])))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->join('');
    $s = $this->stats;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex items-center gap-3 border-b border-stone-200 bg-white px-4 py-3 sm:px-6">
        <a href="{{ route('anggota') }}" wire:navigate aria-label="Kembali ke daftar anggota"
           class="grid size-9 shrink-0 place-items-center rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></svg>
        </a>
        <p class="text-sm text-stone-500">Anggota & Tabungan <span class="text-stone-300">/</span> <span class="text-stone-800">{{ $m['name'] }}</span></p>
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div class="space-y-5">
            {{-- Identitas --}}
            <section class="flex items-center gap-4 rounded-xl border border-stone-200 bg-white p-5">
                <span @class(['grid size-16 shrink-0 place-items-center rounded-full text-xl font-semibold',
                              'bg-emerald-100 text-emerald-800' => $m['type'] === 'siswa',
                              'bg-amber-100 text-amber-900' => $m['type'] === 'guru'])>{{ $initials }}</span>
                <div class="min-w-0">
                    <h1 class="truncate text-xl font-semibold">{{ $m['name'] }}</h1>
                    <p class="text-sm text-stone-600">
                        {{ \App\Livewire\Anggota\Index::TYPES[$m['type']] }}{{ $m['class'] ? ' · Kelas '.$m['class'] : '' }}
                        @if ($m['is_active'])
                            <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-medium text-emerald-800">Aktif</span>
                        @else
                            <span class="ml-1 rounded bg-stone-200 px-1.5 py-0.5 text-[11px] font-medium text-stone-600">Nonaktif</span>
                        @endif
                    </p>
                    <dl class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-stone-500">
                        <div><dt class="inline">Kode:</dt> <dd class="inline font-mono text-stone-800">{{ $m['member_code'] }}</dd></div>
                        <div><dt class="inline">HP:</dt> <dd class="inline text-stone-800">{{ $m['phone'] ?? '—' }}</dd></div>
                        <div><dt class="inline">Bergabung:</dt> <dd class="inline text-stone-800">{{ \Illuminate\Support\Carbon::parse($m['joined_at'])->translatedFormat('d F Y') }}</dd></div>
                    </dl>
                </div>
            </section>

            {{-- Ringkasan tabungan --}}
            <section class="grid grid-cols-2 gap-3" aria-label="Ringkasan tabungan">
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Total setoran</p>
                    <p class="text-lg font-semibold text-emerald-800 tabular-nums">+{{ $rp($s['deposit_total']) }}</p>
                    <p class="text-xs text-stone-500">{{ $s['deposit_count'] }} kali setor</p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Total penarikan</p>
                    <p class="text-lg font-semibold text-red-700 tabular-nums">−{{ $rp($s['withdraw_total']) }}</p>
                    <p class="text-xs text-stone-500">{{ $s['withdraw_count'] }} kali tarik</p>
                </div>
            </section>

            @if (count($this->savings) === 0)
                <div class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-10 text-center">
                    <p class="text-sm font-medium text-stone-600">Belum ada mutasi tabungan</p>
                    <p class="text-xs text-stone-400">Setoran pertama akan tampil di sini.</p>
                </div>
            @endif
        </div>

        {{-- Kartu saldo --}}
        <aside class="space-y-3">
            <div class="rounded-2xl bg-emerald-900 p-5 text-white shadow-sm">
                <p class="text-sm text-emerald-200">Saldo tabungan</p>
                <p class="mt-1 text-3xl font-bold tabular-nums">{{ $rp($m['balance']) }}</p>
                <p class="mt-3 text-xs text-emerald-200">
                    @if ($s['last_at'])
                        Mutasi terakhir: {{ $s['last_type'] === 'setor' ? 'setor' : 'tarik' }}, {{ \Illuminate\Support\Carbon::parse($s['last_at'])->diffForHumans() }}
                    @else
                        Belum pernah menabung
                    @endif
                </p>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <button type="button" wire:click="openSaving('setor')" @disabled(! $m['is_active'])
                            class="rounded-xl bg-white py-2.5 text-sm font-semibold text-emerald-900 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-60">+ Setor</button>
                    <button type="button" wire:click="openSaving('tarik')" @disabled(! $m['is_active'] || $m['balance'] <= 0)
                            class="rounded-xl border border-emerald-400/60 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50">− Tarik</button>
                </div>
                @unless ($m['is_active'])
                    <p class="mt-2 text-xs text-emerald-200">Anggota nonaktif tidak dapat setor/tarik.</p>
                @endunless
            </div>
            @if ($lastEntry)
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
                    {{ $lastEntry['type'] === 'setor' ? 'Setoran' : 'Penarikan' }} <strong class="tabular-nums">{{ $rp($lastEntry['amount']) }}</strong> tercatat.
                    Saldo sekarang <strong class="tabular-nums">{{ $rp($lastEntry['balance_after']) }}</strong>.
                </div>
            @endif
            <p class="text-xs text-stone-400">Data tiruan — saldo & mutasi akan diambil dari database pada tahap backend.</p>
        </aside>
    </div>

    @include('livewire.anggota.partials.saving-form')
</div>
