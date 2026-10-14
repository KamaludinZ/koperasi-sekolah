{{-- Dialog keputusan pengajuan: setujui (catatan opsional) / tolak (alasan wajib) --}}
@if ($decidingId && ($d = $this->deciding))
    @php $approve = $decision === 'disetujui'; @endphp
    <div class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="decision-title"
         x-data x-on:keydown.escape.window="$wire.closeDecision()">
        <div class="absolute inset-0 bg-stone-950/50" wire:click="closeDecision"></div>
        <form wire:submit="decide" class="relative w-full max-w-md rounded-2xl bg-white p-5 shadow-xl" data-decision="{{ $decision }}">
            <h2 id="decision-title" class="font-semibold">{{ $approve ? 'Setujui pengajuan?' : 'Tolak pengajuan?' }}</h2>
            <p class="mt-1 text-sm text-stone-600">
                <span class="font-mono">{{ $d['code'] }}</span> · {{ $d['member']['name'] ?? '—' }} —
                {{ \App\Support\Rupiah::format($d['principal']) }} + bunga {{ \App\Support\Rupiah::format($d['interest']) }}, {{ $d['tenor_months'] }} bulan
            </p>
            <label class="mt-4 block">
                <span class="mb-1 block text-sm font-medium">{{ $approve ? 'Catatan' : 'Alasan penolakan' }} @if ($approve)<span class="font-normal text-stone-500">(opsional)</span>@endif</span>
                <textarea wire:model="decisionNote" rows="3" maxlength="200" autofocus placeholder="{{ $approve ? 'Mis. memenuhi syarat, cair awal bulan' : 'Mis. masih ada pinjaman menunggak' }}"
                          class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15"></textarea>
                @error('decisionNote') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="closeDecision" class="rounded-xl border border-stone-300 px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="decide"
                        @class(['flex-1 rounded-xl py-2.5 text-sm font-semibold text-white disabled:opacity-60', 'bg-emerald-700 hover:bg-emerald-800' => $approve, 'bg-red-700 hover:bg-red-800' => ! $approve])>
                    {{ $approve ? 'Setujui' : 'Tolak pengajuan' }}
                </button>
            </div>
        </form>
    </div>
@endif
