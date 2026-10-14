@php $rp = fn ($n) => \App\Support\Rupiah::format($n); $loans = $this->loans; $t = $this->totals; @endphp
<div class="space-y-5">
    <h1 class="text-xl font-semibold">Pinjaman saya</h1>

    @if ($loans === [])
        <div class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-12 text-center" data-empty="pinjaman-saya">
            <p class="font-medium text-stone-700">Belum ada pinjaman</p>
            <p class="mt-1 text-sm text-stone-500">Pengajuan pinjaman dicatat oleh pengurus koperasi. Hubungi pengurus bila ingin mengajukan pinjaman.</p>
        </div>
    @else
        @if ($t['active'] > 0)
            <section class="rounded-2xl bg-emerald-900 p-4 text-white" aria-label="Ringkasan pinjaman">
                <p class="text-xs text-emerald-200">Sisa pinjaman berjalan</p>
                <p class="text-2xl font-bold tabular-nums" data-total-outstanding>{{ $rp($t['outstanding']) }}</p>
                <p class="text-xs text-emerald-200">{{ $t['active'] }} pinjaman aktif</p>
                @if ($t['next'])
                    <p class="mt-3 border-t border-emerald-800 pt-3 text-sm" data-next-due>Angsuran terdekat: <span class="font-semibold tabular-nums">{{ $rp($t['next']['remaining']) }}</span> · {{ $t['next']['code'] }} ke-{{ $t['next']['sequence'] }} · jatuh tempo @tanggal($t['next']['due_date'])</p>
                @endif
                @if ($t['late_amount'] > 0)
                    <p class="mt-2 rounded-lg bg-red-500/20 px-3 py-1.5 text-sm text-red-50" data-late-amount>Tunggakan {{ $rp($t['late_amount']) }} — segera hubungi pengurus.</p>
                @endif
            </section>
        @endif

        <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-100 p-1" role="tablist" aria-label="Saring pinjaman">
            @foreach (\App\Livewire\Portal\Loans::TABS as $k => $tab)
                <button type="button" role="tab" wire:click="setTab('{{ $k }}')" aria-selected="{{ $this->tab === $k ? 'true' : 'false' }}" data-loan-tab="{{ $k }}"
                        @class(['flex-1 whitespace-nowrap rounded-lg px-3 py-1 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $this->tab === $k, 'text-stone-600' => $this->tab !== $k])>{{ $tab['label'] }} <span class="tabular-nums text-xs text-stone-500">{{ $this->counts[$k] }}</span></button>
            @endforeach
        </div>

        <x-portal.loan-list :loans="$this->shown" :empty="\App\Livewire\Portal\Loans::TABS[$this->tab]['empty']" />
    @endif
</div>
