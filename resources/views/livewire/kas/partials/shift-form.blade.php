{{-- Dialog buka shift (modal awal) / tutup shift (hitung uang laci & selisih) / setor-tarik kas laci --}}
@if ($dialog)
    @php
        $isOpen = $dialog === 'buka';
        $shift = $this->openShiftRow;
        $field = 'w-full rounded-lg border border-stone-300 px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
        $money = 'w-full [appearance:textfield] rounded-xl border border-stone-300 py-3 pr-4 pl-11 text-xl font-semibold tabular-nums outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15 [&::-webkit-inner-spin-button]:appearance-none';
    @endphp
    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="shift-title"
         x-on:keydown.escape.window="$wire.closeDialog()">
        <div class="absolute inset-0 bg-stone-950/50" wire:click="closeDialog"></div>

        @if ($isOpen)
            <form wire:submit="openShift" class="relative w-full max-w-md rounded-t-2xl bg-white shadow-xl sm:rounded-2xl"
                  x-data="{ amount: $wire.entangle('openingCash') }" x-init="$nextTick(() => $refs.amount.focus())">
                <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                    <div>
                        <h2 id="shift-title" class="font-semibold">Buka shift</h2>
                        <p class="text-xs text-stone-500">{{ auth()->user()->name }} · {{ now()->translatedFormat('l, d F Y · H:i') }}</p>
                    </div>
                    <button type="button" wire:click="closeDialog" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>

                <div class="space-y-4 px-5 py-5">
                    <fieldset>
                        <legend class="mb-2 text-sm font-medium">Shift</legend>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach (\App\Livewire\Kas\Index::SHIFT_NAMES as $name)
                                <label @class(['cursor-pointer rounded-lg border px-2 py-2 text-center text-sm font-medium',
                                               'border-emerald-600 bg-emerald-50 text-emerald-900' => $shiftName === $name,
                                               'border-stone-300 text-stone-600 hover:bg-stone-50' => $shiftName !== $name])>
                                    <input type="radio" wire:model.live="shiftName" value="{{ $name }}" class="sr-only">{{ ucfirst(\Illuminate\Support\Str::after($name, 'Shift ')) }}
                                </label>
                            @endforeach
                        </div>
                        @error('shiftName') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </fieldset>

                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">Modal awal di laci</span>
                        <span class="mb-2 block text-xs text-stone-500">Uang kembalian yang ada di laci saat shift dimulai.</span>
                        <div class="relative">
                            <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-stone-500">Rp</span>
                            <input type="number" inputmode="numeric" min="0" step="500" x-ref="amount" x-model.number="amount" class="{{ $money }}" @error('openingCash') aria-invalid="true" @enderror>
                        </div>
                    </label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ([100000, 150000, 200000, 300000, 500000] as $n)
                            <button type="button" x-on:click="amount = {{ $n }}" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium tabular-nums hover:border-emerald-600 hover:bg-emerald-50">{{ $rp($n) }}</button>
                        @endforeach
                    </div>
                    @error('openingCash') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                    <button type="button" wire:click="closeDialog" class="rounded-xl border border-stone-300 px-4 py-3 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="openShift" :disabled="amount === null || amount === '' || amount < 0"
                            class="flex-1 rounded-xl bg-emerald-700 py-3 text-base font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-stone-300">Mulai shift</button>
                </div>
            </form>
        @elseif ($dialog === 'tutup' && $shift)
            <form wire:submit="closeShift" class="relative flex max-h-[95dvh] w-full max-w-lg flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl"
                  x-data="{
                      amount: $wire.entangle('countedCash'),
                      expected: {{ $shift['expected'] }},
                      counts: {},
                      useCounter: false,
                      sumCounts() { this.amount = Object.entries(this.counts).reduce((t, [d, n]) => t + d * (parseInt(n) || 0), 0) },
                      fmt(n) { return 'Rp' + Math.abs(n).toLocaleString('id-ID') },
                      get diff() { return (this.amount === null || this.amount === '') ? null : this.amount - this.expected },
                  }" x-init="$nextTick(() => $refs.amount.focus())">
                <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                    <div>
                        <h2 id="shift-title" class="font-semibold">Tutup {{ \Illuminate\Support\Str::lower($shift['name']) }}</h2>
                        <p class="text-xs text-stone-500">{{ $shift['cashier'] }} · sejak {{ \Illuminate\Support\Carbon::parse($shift['opened_at'])->format('H:i') }} · {{ $shift['transactions'] }} transaksi</p>
                    </div>
                    <button type="button" wire:click="closeDialog" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5">
                    <dl class="space-y-1 rounded-xl bg-stone-50 px-4 py-3 text-sm">
                        <div class="flex justify-between"><dt class="text-stone-500">Modal awal</dt><dd class="tabular-nums">{{ $rp($shift['opening_cash']) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-stone-500">+ Penjualan tunai</dt><dd class="tabular-nums">{{ $rp($shift['cash_sales']) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-stone-500">+ Kas masuk</dt><dd class="tabular-nums">{{ $rp($shift['cash_in']) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-stone-500">− Kas keluar</dt><dd class="tabular-nums">{{ $rp($shift['cash_out']) }}</dd></div>
                        <div class="flex justify-between border-t border-stone-200 pt-1 font-semibold"><dt>Kas seharusnya</dt><dd class="tabular-nums">{{ $rp($shift['expected']) }}</dd></div>
                    </dl>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <label for="counted" class="text-sm font-medium">Uang dihitung di laci</label>
                            <button type="button" x-on:click="useCounter = !useCounter" class="text-xs font-semibold text-emerald-800 hover:underline" x-text="useCounter ? 'Ketik langsung' : 'Hitung per pecahan'"></button>
                        </div>
                        <div class="relative">
                            <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-stone-500">Rp</span>
                            <input id="counted" type="number" inputmode="numeric" min="0" step="100" x-ref="amount" x-model.number="amount" :readonly="useCounter" class="{{ $money }} read-only:bg-stone-50" @error('countedCash') aria-invalid="true" @enderror>
                        </div>
                        @error('countedCash') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div x-show="useCounter" x-cloak class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-xl border border-stone-200 p-3 sm:grid-cols-2">
                        @foreach (\App\Livewire\Kas\Index::DENOMINATIONS as $d)
                            <label class="flex items-center justify-between gap-2 text-sm">
                                <span class="tabular-nums text-stone-600">{{ $rp($d) }}<span class="sr-only"> jumlah lembar/keping</span></span>
                                <input type="number" min="0" inputmode="numeric" x-model="counts[{{ $d }}]" x-on:input="sumCounts()" placeholder="0"
                                       class="w-16 rounded-md border border-stone-300 px-2 py-1 text-right tabular-nums outline-none focus:border-emerald-600">
                            </label>
                        @endforeach
                    </div>

                    <div class="flex items-center justify-between rounded-xl border border-dashed px-4 py-3"
                         :class="diff === null ? 'border-stone-300' : (diff === 0 ? 'border-emerald-300 bg-emerald-50' : (diff < 0 ? 'border-red-300 bg-red-50' : 'border-amber-300 bg-amber-50'))"
                         aria-live="polite">
                        <span class="text-sm text-stone-600">Selisih kas</span>
                        <span class="text-lg font-bold tabular-nums"
                              :class="diff === null ? 'text-stone-400' : (diff === 0 ? 'text-emerald-800' : (diff < 0 ? 'text-red-700' : 'text-amber-800'))"
                              x-text="diff === null ? '—' : (diff === 0 ? 'Pas' : (diff < 0 ? 'Kurang ' : 'Lebih ') + fmt(diff))"></span>
                    </div>

                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">Catatan <span class="font-normal text-stone-500" x-text="diff ? '(wajib karena ada selisih)' : '(opsional)'">(opsional)</span></span>
                        <textarea wire:model="closeNote" rows="2" maxlength="200" placeholder="mis. Salah kembalian ke pembeli" class="{{ $field }}" @error('closeNote') aria-invalid="true" @enderror></textarea>
                        @error('closeNote') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                    <button type="button" wire:click="closeDialog" class="rounded-xl border border-stone-300 px-4 py-3 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="closeShift" :disabled="diff === null || amount < 0"
                            class="flex-1 rounded-xl bg-emerald-700 py-3 text-base font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-stone-300">Tutup shift</button>
                </div>
            </form>
        @elseif ($dialog === 'kas' && $shift)
            @php $isIn = $movementType === 'in'; @endphp
            <form wire:submit="saveMovement" class="relative w-full max-w-md rounded-t-2xl bg-white shadow-xl sm:rounded-2xl"
                  x-data="{ amount: $wire.entangle('movementAmount'), expected: {{ $shift['expected'] }}, deposit: {{ $isIn ? 'true' : 'false' }} }"
                  x-init="$nextTick(() => $refs.amount.focus())">
                <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                    <div>
                        <h2 id="shift-title" class="font-semibold">Setor / tarik kas laci</h2>
                        <p class="text-xs text-stone-500">{{ $shift['name'] }} · kas seharusnya {{ $rp($shift['expected']) }}</p>
                    </div>
                    <button type="button" wire:click="closeDialog" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>

                <div class="space-y-4 px-5 py-5">
                    <div class="grid grid-cols-2 gap-2" role="group" aria-label="Jenis catatan kas">
                        <button type="button" wire:click="setMovementType('in')" aria-pressed="{{ $isIn ? 'true' : 'false' }}"
                                @class(['rounded-lg border px-3 py-2 text-sm font-medium', 'border-emerald-600 bg-emerald-50 text-emerald-900' => $isIn, 'border-stone-300 text-stone-600' => ! $isIn])>Uang masuk laci</button>
                        <button type="button" wire:click="setMovementType('out')" aria-pressed="{{ $isIn ? 'false' : 'true' }}"
                                @class(['rounded-lg border px-3 py-2 text-sm font-medium', 'border-red-600 bg-red-50 text-red-800' => ! $isIn, 'border-stone-300 text-stone-600' => $isIn])>Uang keluar laci</button>
                    </div>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium">Jumlah</span>
                        <div class="relative">
                            <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-stone-500">Rp</span>
                            <input type="number" inputmode="numeric" min="500" step="500" x-ref="amount" x-model.number="amount" class="{{ $money }}" @error('movementAmount') aria-invalid="true" @enderror>
                        </div>
                    </label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ([5000, 10000, 20000, 50000, 100000] as $n)
                            <button type="button" x-on:click="amount = {{ $n }}" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium tabular-nums hover:border-emerald-600 hover:bg-emerald-50">{{ $rp($n) }}</button>
                        @endforeach
                    </div>
                    @error('movementAmount') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                    <div class="flex items-baseline justify-between rounded-xl border border-dashed px-4 py-3"
                         :class="!deposit && (amount || 0) > expected ? 'border-red-300' : 'border-stone-300'">
                        <span class="text-sm text-stone-600">Kas seharusnya setelah ini</span>
                        <span class="text-xl font-bold tabular-nums"
                              :class="!deposit && (amount || 0) > expected ? 'text-red-600' : 'text-emerald-900'"
                              x-text="'Rp' + (expected + (deposit ? 1 : -1) * (amount || 0)).toLocaleString('id-ID')">{{ $rp($shift['expected']) }}</span>
                    </div>

                    <div>
                        <label for="movement-reason" class="mb-1 block text-sm font-medium">Alasan</label>
                        <input id="movement-reason" type="text" wire:model="movementReason" maxlength="150" placeholder="{{ $isIn ? 'mis. Tambah uang kembalian' : 'mis. Setor ke bendahara' }}" class="{{ $field }}" @error('movementReason') aria-invalid="true" @enderror>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach (\App\Livewire\Kas\Index::MOVEMENT_REASONS[$movementType] as $reason)
                                <button type="button" wire:click="$set('movementReason', @js($reason))" class="rounded-full bg-stone-100 px-2.5 py-1 text-xs text-stone-700 hover:bg-emerald-100 hover:text-emerald-900">{{ $reason }}</button>
                            @endforeach
                        </div>
                        @error('movementReason') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                    <button type="button" wire:click="closeDialog" class="rounded-xl border border-stone-300 px-4 py-3 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveMovement" :disabled="(amount || 0) < 500 || (!deposit && amount > expected)"
                            @class(['flex-1 rounded-xl py-3 text-base font-semibold text-white disabled:cursor-not-allowed disabled:bg-stone-300',
                                    'bg-emerald-700 hover:bg-emerald-800' => $isIn, 'bg-red-600 hover:bg-red-700' => ! $isIn])>
                        {{ $isIn ? 'Catat uang masuk' : 'Catat uang keluar' }}
                    </button>
                </div>
            </form>
        @endif
    </div>
@endif
