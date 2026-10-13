{{-- Dialog pelunasan bon: tunai atau potong tabungan, boleh sebagian; dibagi ke bon jatuh tempo terlama dulu. --}}
@if ($repaying)
    @php
        $cr = $this->credit;
        $balance = $profile['balance'];
        $quick = array_values(array_unique(array_filter([...array_column($bills, 'remaining'), $cr['outstanding']])));
    @endphp
    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="repay-title"
         x-data="{ amount: $wire.entangle('repayAmount'), method: $wire.entangle('repayMethod'), owed: {{ $cr['outstanding'] }}, balance: {{ $balance }},
                  bills: @js(array_map(fn ($b) => ['n' => $b['number'], 'r' => $b['remaining']], $bills)),
                  get max() { return this.method === 'tabungan' ? Math.min(this.owed, this.balance) : this.owed },
                  get plan() { let left = this.amount || 0; return this.bills.map(b => { const p = Math.min(left, b.r); left -= p; return { ...b, p } }) },
                  rp(n) { return 'Rp' + n.toLocaleString('id-ID') } }"
         x-init="$nextTick(() => $refs.amount.select())"
         x-on:keydown.escape.window="$wire.closeRepay()">
        <div class="absolute inset-0 bg-stone-950/50" wire:click="closeRepay"></div>

        <form wire:submit="saveRepay" class="relative w-full max-w-md rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <div>
                    <h2 id="repay-title" class="font-semibold">Pelunasan bon</h2>
                    <p class="text-xs text-stone-500">{{ $profile['name'] }} · sisa bon {{ $rp($cr['outstanding']) }}</p>
                </div>
                <button type="button" wire:click="closeRepay" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="space-y-4 px-5 py-5">
                <fieldset>
                    <legend class="mb-2 text-sm font-medium">Dibayar dengan</legend>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (\App\Livewire\Anggota\Show::REPAY_METHODS as $value => $label)
                            <label class="flex cursor-pointer flex-col items-center rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 has-checked:border-emerald-600 has-checked:bg-emerald-50 has-checked:text-emerald-900 has-disabled:cursor-not-allowed has-disabled:opacity-50">
                                <input type="radio" value="{{ $value }}" x-model="method" class="sr-only" @disabled($value === 'tabungan' && $balance <= 0)>
                                {{ $label }}
                                @if ($value === 'tabungan')<span class="text-xs font-normal text-stone-500 tabular-nums">saldo {{ $rp($balance) }}</span>@endif
                            </label>
                        @endforeach
                    </div>
                    @error('repayMethod') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </fieldset>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium">Jumlah dilunasi</span>
                    <div class="relative">
                        <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-stone-500">Rp</span>
                        <input type="number" inputmode="numeric" min="500" step="500" x-ref="amount" x-model.number="amount" :max="max"
                               class="w-full [appearance:textfield] rounded-xl border border-stone-300 py-3 pr-4 pl-11 text-xl font-semibold tabular-nums outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15 [&::-webkit-inner-spin-button]:appearance-none">
                    </div>
                </label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($quick as $n)
                        <button type="button" x-on:click="amount = {{ $n }}" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium tabular-nums hover:border-emerald-600 hover:bg-emerald-50">{{ $n === $cr['outstanding'] ? 'Lunasi semua' : $rp($n) }}</button>
                    @endforeach
                </div>
                @error('repayAmount') <p class="text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                <p x-show="(amount || 0) > max" x-cloak class="text-sm text-red-600"
                   x-text="method === 'tabungan' && balance < owed ? 'Saldo tabungan hanya ' + rp(balance) + '.' : 'Melebihi sisa bon ' + rp(owed) + '.'"></p>

                <div class="rounded-xl border border-dashed border-stone-300 px-4 py-3 text-sm">
                    <p class="mb-1.5 text-xs font-medium text-stone-500">Dibagi ke bon (jatuh tempo terlama dulu)</p>
                    <template x-for="b in plan" :key="b.n">
                        <div class="flex justify-between gap-2 py-0.5">
                            <span class="font-mono text-xs" x-text="b.n"></span>
                            <span class="text-xs tabular-nums" :class="b.p >= b.r ? 'font-semibold text-emerald-800' : (b.p > 0 ? 'text-amber-800' : 'text-stone-400')"
                                  x-text="b.p >= b.r ? 'lunas' : (b.p > 0 ? rp(b.p) + ' · sisa ' + rp(b.r - b.p) : 'belum')"></span>
                        </div>
                    </template>
                    <div class="mt-1.5 flex items-baseline justify-between border-t border-stone-200 pt-1.5">
                        <span>Sisa bon setelahnya</span>
                        <span class="text-lg font-bold tabular-nums" x-text="rp(Math.max(0, owed - (amount || 0)))"></span>
                    </div>
                    <p x-show="method === 'tabungan'" x-cloak class="text-xs text-stone-500">Saldo tabungan menjadi <span class="tabular-nums" x-text="rp(Math.max(0, balance - (amount || 0)))"></span>.</p>
                </div>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Catatan <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="text" wire:model="repayNote" maxlength="150" placeholder="mis. Dibayar orang tua"
                           class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                    @error('repayNote') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                <button type="button" wire:click="closeRepay" class="rounded-xl border border-stone-300 px-4 py-3 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" :disabled="(amount || 0) < Math.min(500, owed) || (amount || 0) > max"
                        wire:loading.attr="disabled" wire:target="saveRepay"
                        class="flex-1 rounded-xl bg-emerald-700 py-3 text-base font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-stone-300">Simpan pelunasan</button>
            </div>
        </form>
    </div>
@endif
