{{-- Dialog pembatalan pengajuan yang belum dicairkan (alasan wajib) --}}
@if ($cancellingId && ($c = $this->cancelling))
    <div class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="cancel-title"
         x-data x-on:keydown.escape.window="$wire.closeCancel()">
        <div class="absolute inset-0 bg-stone-950/50" wire:click="closeCancel"></div>
        <form wire:submit="confirmCancel" class="relative w-full max-w-md rounded-2xl bg-white p-5 shadow-xl" data-cancel-dialog>
            <h2 id="cancel-title" class="font-semibold">Batalkan pengajuan?</h2>
            <p class="mt-1 text-sm text-stone-600"><span class="font-mono">{{ $c['code'] }}</span> · {{ $c['member']['name'] ?? '—' }} — {{ \App\Support\Rupiah::format($c['principal']) }}, status {{ mb_strtolower($c['status_label']) }}.</p>
            <p class="mt-1 text-xs text-stone-500">Pengajuan yang dibatalkan tetap tersimpan sebagai riwayat dan tidak bisa dicairkan.</p>
            <label class="mt-4 block">
                <span class="mb-1 block text-sm font-medium">Alasan pembatalan</span>
                <textarea wire:model="cancelReason" rows="3" maxlength="200" autofocus placeholder="Mis. anggota menarik pengajuan"
                          class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15"></textarea>
                @error('cancelReason') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="closeCancel" class="rounded-xl border border-stone-300 px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Kembali</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="confirmCancel" class="flex-1 rounded-xl bg-red-700 py-2.5 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-60">Batalkan pengajuan</button>
            </div>
        </form>
    </div>
@endif
