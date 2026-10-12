{{-- Dialog setor / tarik tabungan --}}
@if ($mode)
    @php
        $isDeposit = $mode === 'setor';
        $balance = $profile['balance'];
        $quick = $isDeposit ? [5000, 10000, 20000, 50000, 100000] : array_values(array_filter([10000, 20000, 50000, 100000], fn ($n) => $n <= $balance));
    @endphp
    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="saving-title"
         x-data="{ amount: $wire.entangle('amount'), balance: {{ $balance }}, deposit: {{ $isDeposit ? 'true' : 'false' }} }"
         x-init="$nextTick(() => $refs.amount.focus())"
         x-on:keydown.escape.window="$wire.closeSaving()">
        <div class="absolute inset-0 bg-stone-950/50" wire:click="closeSaving"></div>

        <form wire:submit="saveSaving" class="relative w-full max-w-md rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <div>
                    <h2 id="saving-title" class="font-semibold">{{ $isDeposit ? 'Setor tabungan' : 'Tarik tabungan' }}</h2>
                    <p class="text-xs text-stone-500">{{ $profile['name'] }} · saldo {{ $rp($balance) }}</p>
                </div>
                <button type="button" wire:click="closeSaving" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="space-y-4 px-5 py-5">
                <div class="grid grid-cols-2 gap-2" role="group" aria-label="Jenis mutasi">
                    <button type="button" wire:click="openSaving('setor')"
                            @class(['rounded-lg border px-3 py-2 text-sm font-medium', 'border-emerald-600 bg-emerald-50 text-emerald-900' => $isDeposit, 'border-stone-300 text-stone-600' => ! $isDeposit])>Setor</button>
                    <button type="button" wire:click="openSaving('tarik')" @disabled($balance <= 0)
                            @class(['rounded-lg border px-3 py-2 text-sm font-medium disabled:opacity-40', 'border-red-600 bg-red-50 text-red-800' => ! $isDeposit, 'border-stone-300 text-stone-600' => $isDeposit])>Tarik</button>
                </div>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium">Jumlah</span>
                    <div class="relative">
                        <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-stone-500">Rp</span>
                        <input type="number" inputmode="numeric" min="1000" step="500" x-ref="amount" x-model.number="amount"
                               class="w-full [appearance:textfield] rounded-xl border border-stone-300 py-3 pr-4 pl-11 text-xl font-semibold tabular-nums outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15 [&::-webkit-inner-spin-button]:appearance-none">
                    </div>
                </label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($quick as $n)
                        <button type="button" x-on:click="amount = {{ $n }}" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium tabular-nums hover:border-emerald-600 hover:bg-emerald-50">{{ $rp($n) }}</button>
                    @endforeach
                    @unless ($isDeposit)
                        <button type="button" x-on:click="amount = {{ $balance }}" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium hover:border-red-600 hover:bg-red-50">Semua saldo</button>
                    @endunless
                </div>
                @error('amount') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="flex items-baseline justify-between rounded-xl border border-dashed px-4 py-3"
                     :class="!deposit && (amount || 0) > balance ? 'border-red-300' : 'border-stone-300'">
                    <span class="text-sm text-stone-600">Saldo setelah {{ $isDeposit ? 'setor' : 'tarik' }}</span>
                    <span class="text-xl font-bold tabular-nums"
                          :class="!deposit && (amount || 0) > balance ? 'text-red-600' : 'text-emerald-900'"
                          x-text="'Rp' + (balance + (deposit ? 1 : -1) * (amount || 0)).toLocaleString('id-ID')"></span>
                </div>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Catatan <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="text" wire:model="note" maxlength="150" placeholder="{{ $isDeposit ? 'mis. Tabungan mingguan' : 'mis. Untuk study tour' }}"
                           class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                    @error('note') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                <button type="button" wire:click="closeSaving" class="rounded-xl border border-stone-300 px-4 py-3 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" :disabled="(amount || 0) < 1000 || (!deposit && amount > balance)"
                        wire:loading.attr="disabled" wire:target="saveSaving"
                        @class(['flex-1 rounded-xl py-3 text-base font-semibold text-white disabled:cursor-not-allowed disabled:bg-stone-300',
                                'bg-emerald-700 hover:bg-emerald-800' => $isDeposit, 'bg-red-600 hover:bg-red-700' => ! $isDeposit])>
                    {{ $isDeposit ? 'Simpan setoran' : 'Simpan penarikan' }}
                </button>
            </div>
        </form>
    </div>
@endif
