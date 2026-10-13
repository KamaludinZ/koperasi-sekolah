{{-- Panel bayar & kembalian. Kembalian dihitung instan di klien; server memvalidasi ulang saat konfirmasi. --}}
@php
    $total = $summary['totals']['tunai']['payable'] ?? $summary['total']; // tombol nominal cepat hanya untuk tunai
    $quick = collect([5000, 10000, 20000, 50000, 100000])
        ->map(fn ($step) => (int) (ceil($total / $step) * $step))
        ->prepend($total)
        ->filter(fn ($n) => $n >= $total && $n > 0)
        ->unique()
        ->take(5)
        ->values();
@endphp

@if ($paying)
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-stone-950/50 p-0 sm:items-center sm:p-4"
         x-data="{ paid: $wire.entangle('paid'), method: $wire.entangle('paymentMethod'), totals: @js($summary['totals']),
                  get total() { return this.totals[this.method]?.payable ?? 0 }, get rounding() { return this.totals[this.method]?.rounding ?? 0 },
                  rp(n) { return window.rupiah ? window.rupiah(Math.abs(n)) : 'Rp' + Math.abs(n).toLocaleString('id-ID') } }"
         x-init="$nextTick(() => $refs.paid?.focus())"
         x-on:keydown.escape.window="$wire.closePayment()"
         role="dialog" aria-modal="true" aria-labelledby="payment-title">
        <div class="absolute inset-0" wire:click="closePayment"></div>

        <form wire:submit="pay" class="relative w-full max-w-md rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <h2 id="payment-title" class="font-semibold">Pembayaran</h2>
                <button type="button" wire:click="closePayment" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="space-y-5 px-5 py-5">
                @error('items')
                    <p class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ $message }}</p>
                @enderror
                <div class="rounded-xl bg-emerald-50 px-4 py-3">
                    <p class="text-xs font-medium text-emerald-800">Total belanja · {{ $summary['count'] }} barang</p>
                    <p class="text-3xl font-bold text-emerald-900 tabular-nums" x-text="rp(total)">{{ $rp($summary['total']) }}</p>
                    <p x-show="rounding !== 0" x-cloak class="text-xs text-emerald-800 tabular-nums">
                        Termasuk pembulatan <span x-text="(rounding > 0 ? '+' : '−') + rp(rounding)"></span>
                    </p>
                </div>

                <fieldset>
                    <legend class="mb-2 text-sm font-medium">Metode bayar</legend>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($this->methods as $value => $label)
                            <label class="flex cursor-pointer items-center justify-center rounded-lg border px-3 py-2.5 text-sm font-medium transition has-checked:border-emerald-600 has-checked:bg-emerald-50 has-checked:text-emerald-900 border-stone-300 text-stone-700">
                                <input type="radio" value="{{ $value }}" x-model="method" class="sr-only">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('paymentMethod') <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">{{ $message }}</p> @enderror
                </fieldset>

                {{-- Anggota (wajib untuk tabungan & bon) --}}
                <div x-show="method === 'tabungan' || method === 'bon'" x-cloak class="space-y-3">
                    @php $m = $this->member; @endphp
                    @if ($m)
                        <div class="rounded-xl border border-stone-200 p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold">{{ $m['display_name'] }}</p>
                                    <p class="font-mono text-xs text-stone-500">{{ $m['member_code'] }}</p>
                                </div>
                                <button type="button" wire:click="clearMember" class="shrink-0 text-xs font-medium text-stone-500 hover:text-stone-800">Ganti</button>
                            </div>
                            <dl class="mt-2 grid grid-cols-2 gap-2 text-xs">
                                <div class="rounded-lg bg-stone-50 px-2.5 py-1.5" :class="method === 'tabungan' && 'ring-1 ring-emerald-500'">
                                    <dt class="text-stone-500">Saldo tabungan</dt>
                                    <dd class="text-sm font-semibold tabular-nums">{{ $rp($m['balance']) }}</dd>
                                </div>
                                <div class="rounded-lg bg-stone-50 px-2.5 py-1.5" :class="method === 'bon' && 'ring-1 ring-emerald-500'">
                                    <dt class="text-stone-500">Sisa batas bon</dt>
                                    <dd class="text-sm font-semibold tabular-nums">{{ $rp($m['credit']['available']) }} <span class="font-normal text-stone-500">/ {{ $rp($m['credit']['limit']) }}</span></dd>
                                </div>
                            </dl>
                            @if ($m['credit']['outstanding'] > 0)
                                <p @class(['mt-2 text-xs', 'font-semibold text-red-700' => $m['credit']['overdue'], 'text-stone-600' => ! $m['credit']['overdue']])>
                                    Bon belum lunas {{ $rp($m['credit']['outstanding']) }} ({{ $m['credit']['open_bills'] }} catatan){{ $m['credit']['overdue'] ? ' — lewat jatuh tempo' : '' }}
                                </p>
                            @endif
                            @php $tabShort = \App\Livewire\Kasir\Cart::shortfall('tabungan', $m, $summary['totals']['tabungan']['payable'] ?? $summary['total']);
                                 $bonShort = \App\Livewire\Kasir\Cart::shortfall('bon', $m, $summary['totals']['bon']['payable'] ?? $summary['total']); @endphp
                            @php $tabTotal = $summary['totals']['tabungan']['payable'] ?? $summary['total']; $after = $m['balance'] - $tabTotal; @endphp
                            <div x-show="method === 'tabungan'" class="mt-3 space-y-2 border-t border-stone-100 pt-3" data-savings-breakdown>
                                <dl class="space-y-1 text-xs">
                                    <div class="flex justify-between"><dt class="text-stone-500">Saldo sekarang</dt><dd class="tabular-nums">{{ $rp($m['balance']) }}</dd></div>
                                    <div class="flex justify-between"><dt class="text-stone-500">Dipotong untuk belanja</dt><dd class="tabular-nums text-red-700">−{{ $rp($tabTotal) }}</dd></div>
                                    <div class="flex justify-between border-t border-dashed border-stone-200 pt-1 text-sm font-semibold">
                                        <dt>{{ $tabShort ? 'Kekurangan' : 'Saldo setelah bayar' }}</dt>
                                        <dd @class(['tabular-nums', 'text-red-700' => $tabShort, 'text-emerald-800' => ! $tabShort])>{{ $rp($tabShort ?: $after) }}</dd>
                                    </div>
                                </dl>
                                @if ($m['balance'] > 0)
                                    <div class="h-1.5 overflow-hidden rounded-full bg-stone-100" aria-hidden="true">
                                        <span @class(['block h-full rounded-full', 'bg-red-500' => $tabShort, 'bg-emerald-600' => ! $tabShort]) style="width: {{ min(100, (int) round($tabTotal / $m['balance'] * 100)) }}%"></span>
                                    </div>
                                @endif
                                @if ($tabShort)
                                    <div class="flex items-center justify-between gap-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800" role="status">
                                        <span>Saldo kurang {{ $rp($tabShort) }}. Kurangi belanja atau bayar tunai.</span>
                                        <button type="button" wire:click="switchToCash" class="shrink-0 rounded-md bg-white px-2 py-1 font-semibold text-red-800 ring-1 ring-red-200 hover:bg-red-100">Bayar tunai</button>
                                    </div>
                                @else
                                    @if ($after < \App\Livewire\Kasir\Cart::LOW_BALANCE)
                                        <p class="text-xs text-amber-800">Saldo hampir habis setelah transaksi ini — ingatkan anggota untuk menabung lagi.</p>
                                    @endif
                                    <label class="flex cursor-pointer items-start gap-2 text-sm">
                                        <input type="checkbox" wire:model="savingsConsent" class="mt-0.5 size-4 accent-emerald-700">
                                        <span>Anggota sudah menyetujui saldo tabungannya dipotong {{ $rp($tabTotal) }}.</span>
                                    </label>
                                    @error('savingsConsent') <p class="text-xs text-red-600" role="alert">{{ $message }}</p> @enderror
                                @endif
                            </div>
                            @php $bonTotal = $summary['totals']['bon']['payable'] ?? $summary['total']; $cr = $m['credit']; $bonAfter = $cr['outstanding'] + $bonTotal; @endphp
                            <div x-show="method === 'bon'" class="mt-3 space-y-2 border-t border-stone-100 pt-3" data-bon-breakdown>
                                <dl class="space-y-1 text-xs">
                                    <div class="flex justify-between"><dt class="text-stone-500">Bon berjalan</dt><dd class="tabular-nums">{{ $rp($cr['outstanding']) }}</dd></div>
                                    <div class="flex justify-between"><dt class="text-stone-500">Bon baru (belanja ini)</dt><dd class="tabular-nums">+{{ $rp($bonTotal) }}</dd></div>
                                    <div class="flex justify-between border-t border-dashed border-stone-200 pt-1 text-sm font-semibold">
                                        <dt>Total bon setelah ini</dt>
                                        <dd @class(['tabular-nums', 'text-red-700' => $bonShort])>{{ $rp($bonAfter) }} <span class="font-normal text-stone-500">/ {{ $rp($cr['limit']) }}</span></dd>
                                    </div>
                                </dl>
                                <div class="h-1.5 overflow-hidden rounded-full bg-stone-100" aria-hidden="true">
                                    <span @class(['block h-full rounded-full', 'bg-red-500' => $bonShort, 'bg-amber-500' => ! $bonShort]) style="width: {{ $cr['limit'] ? min(100, (int) round($bonAfter / $cr['limit'] * 100)) : 100 }}%"></span>
                                </div>
                                @if ($cr['oldest_due_at'])
                                    <p @class(['text-xs', 'font-semibold text-red-700' => $cr['overdue'], 'text-stone-500' => ! $cr['overdue']])>Bon tertua jatuh tempo {{ \Illuminate\Support\Carbon::parse($cr['oldest_due_at'])->translatedFormat('d M Y') }}.</p>
                                @endif
                                @if ($bonShort)
                                    <div class="flex items-center justify-between gap-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800" role="status">
                                        <span>Melebihi sisa batas bon sebesar {{ $rp($bonShort) }}.</span>
                                        <button type="button" wire:click="switchToCash" class="shrink-0 rounded-md bg-white px-2 py-1 font-semibold text-red-800 ring-1 ring-red-200 hover:bg-red-100">Bayar tunai</button>
                                    </div>
                                @elseif ($cr['overdue'])
                                    <p class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800" role="status">Bon lama sudah lewat jatuh tempo. Minta dilunasi dulu sebelum menambah bon baru.</p>
                                @endif
                            </div>
                        </div>
                        @unless ($bonShort || $m['credit']['overdue'])
                            <div x-show="method === 'bon'" class="space-y-3">
                                <fieldset>
                                    <legend class="mb-1.5 text-sm font-medium">Jatuh tempo bon</legend>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($this->bonTerms() + [0 => 'Tanggal lain'] as $days => $label)
                                            <label class="cursor-pointer rounded-full border border-stone-300 px-3 py-1 text-sm has-checked:border-emerald-700 has-checked:bg-emerald-900 has-checked:text-white">
                                                <input type="radio" wire:model.live="bonDays" value="{{ $days }}" class="sr-only" @checked($bonDays === $days)> {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                    @if ($bonDays === 0)
                                        <input type="date" wire:model.live="bonDueAt" value="{{ $bonDueAt }}" aria-label="Tanggal jatuh tempo"
                                               min="{{ now()->addDay()->toDateString() }}" max="{{ now()->addDays(\App\Services\MemberCreditService::maxDays())->toDateString() }}"
                                               class="mt-2 w-full rounded-xl border border-stone-300 px-3.5 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                                        @error('bonDueAt') <p class="mt-1 text-xs text-red-600" role="alert">{{ $message }}</p> @enderror
                                    @endif
                                    @if ($due = $this->bonDueDate())
                                        <p class="mt-1 text-xs text-stone-500">Jatuh tempo {{ \Illuminate\Support\Carbon::parse($due)->translatedFormat('l, d M Y') }}.</p>
                                    @endif
                                </fieldset>
                                <label class="block">
                                    <span class="mb-1.5 block text-sm font-medium">Keterangan <span class="font-normal text-stone-500">(opsional)</span></span>
                                    <input type="text" wire:model="bonNote" maxlength="100" placeholder="mis. Dilunasi orang tua saat ambil rapor"
                                           class="w-full rounded-xl border border-stone-300 px-3.5 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                                    @error('bonNote') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                                </label>
                                <label class="flex cursor-pointer items-start gap-2 text-sm">
                                    <input type="checkbox" wire:model="bonConsent" class="mt-0.5 size-4 accent-emerald-700">
                                    <span>Anggota menyetujui bon {{ $rp($bonTotal) }} dan berjanji melunasinya sebelum jatuh tempo.</span>
                                </label>
                                @error('bonConsent') <p class="text-xs text-red-600" role="alert">{{ $message }}</p> @enderror
                            </div>
                        @endunless
                    @else
                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium">Anggota</span>
                            <input type="search" wire:model.live.debounce.250ms="memberSearch" placeholder="Cari nama atau kode anggota…" autocomplete="off"
                                   class="w-full rounded-xl border border-stone-300 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                        </label>
                        @if ($this->memberResults)
                            <ul class="divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200">
                                @foreach ($this->memberResults as $r)
                                    <li><button type="button" wire:click="selectMember({{ $r['id'] }})" class="flex w-full items-center justify-between gap-3 px-3.5 py-2 text-left text-sm hover:bg-emerald-50">
                                        <span class="min-w-0"><span class="block truncate font-medium">{{ $r['display_name'] }}</span><span class="font-mono text-xs text-stone-500">{{ $r['member_code'] }}</span></span>
                                        <span class="shrink-0 text-xs text-stone-600 tabular-nums">{{ $rp($r['balance']) }}</span>
                                    </button></li>
                                @endforeach
                            </ul>
                        @elseif (mb_strlen(trim($memberSearch)) >= 2)
                            <p class="text-xs text-stone-500">Anggota aktif tidak ditemukan.</p>
                        @endif
                    @endif
                    @error('memberId') <p class="text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </div>

                <div x-show="method === 'tunai'" class="space-y-3">
                    <label class="block">
                        <span class="mb-2 block text-sm font-medium">Uang dibayar</span>
                        <div class="relative">
                            <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-stone-500">Rp</span>
                            <input type="number" inputmode="numeric" min="0" step="500" x-ref="paid" x-model.number="paid"
                                   class="w-full [appearance:textfield] rounded-xl border border-stone-300 py-3 pr-4 pl-11 text-xl font-semibold tabular-nums outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15 [&::-webkit-inner-spin-button]:appearance-none">
                        </div>
                    </label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($quick as $amount)
                            <button type="button" x-on:click="paid = {{ $amount }}"
                                    class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium tabular-nums hover:border-emerald-600 hover:bg-emerald-50">
                                {{ $loop->first ? 'Uang pas' : $rp($amount) }}
                            </button>
                        @endforeach
                    </div>
                    @error('paid') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                    <div class="flex items-baseline justify-between rounded-xl border border-dashed px-4 py-3"
                         :class="(paid || 0) >= total ? 'border-emerald-300' : 'border-stone-300'">
                        <span class="text-sm font-medium" x-text="(paid || 0) >= total ? 'Kembalian' : 'Kurang'"></span>
                        <span class="text-2xl font-bold tabular-nums"
                              :class="(paid || 0) >= total ? 'text-emerald-800' : 'text-red-600'"
                              x-text="rp((paid || 0) - total)"></span>
                    </div>
                </div>

                @php $qrisUrl = \App\Services\QrisImageService::url(app(\App\Services\SettingsService::class)->section('profile')['qris'] ?? null); @endphp
                <div x-show="method === 'qris'" x-cloak class="space-y-3 rounded-xl bg-stone-50 px-4 py-3 text-sm text-stone-600" data-qris-panel>
                    @if ($qrisUrl)
                        <div class="flex flex-col items-center gap-2">
                            <img src="{{ $qrisUrl }}" alt="Kode QRIS koperasi untuk dipindai pembeli" class="max-h-64 w-auto rounded-lg bg-white p-2 ring-1 ring-stone-200">
                            <p class="text-center">Minta pembeli memindai QRIS dan membayar tepat <strong class="text-base text-stone-900 tabular-nums" x-text="rp(total)">{{ $rp($summary['total']) }}</strong>.</p>
                        </div>
                    @else
                        <p class="text-xs text-stone-500">Gambar QRIS koperasi belum diunggah (Pengaturan → Profil koperasi).</p>
                    @endif
                    <p>Pastikan pembayaran sebesar <strong class="tabular-nums" x-text="rp(total)">{{ $rp($summary['total']) }}</strong> sudah masuk (cek notifikasi bank) sebelum menyelesaikan transaksi.</p>
                </div>
            </div>

            <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                <button type="button" wire:click="closePayment"
                        class="rounded-xl border border-stone-300 px-4 py-3 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" :disabled="method === 'tunai' && (paid || 0) < total"
                        wire:loading.attr="disabled" wire:target="pay"
                        class="flex-1 rounded-xl bg-emerald-700 py-3 text-base font-semibold text-white shadow-sm transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-stone-300">
                    Selesaikan transaksi
                </button>
            </div>
        </form>
    </div>
@endif

@if ($lastTransaction)
    <div class="fixed inset-0 z-40 flex items-end justify-center bg-stone-950/50 sm:items-center sm:p-4"
         x-data
         x-on:keydown.enter.window.prevent="$wire.newTransaction()"
         x-on:keydown.window.p.prevent="window.print()"
         role="dialog" aria-modal="true" aria-labelledby="success-title">
        <div class="relative flex max-h-full w-full max-w-sm flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            <div class="flex items-center gap-3 border-b border-stone-200 px-5 py-4">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-emerald-100 text-emerald-700">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="m5 13 4 4L19 7"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <h2 id="success-title" class="font-semibold">Transaksi berhasil</h2>
                    <p class="font-mono text-xs text-stone-500">
                        {{ $lastTransaction['invoice_number'] }}
                        · <a href="{{ route('kasir.transactions.receipt', ['transaction' => $lastTransaction['id'], 'cetak' => 0]) }}" target="_blank" class="font-sans text-emerald-700 hover:underline">buka struk</a>
                        · <a href="{{ route('kasir.transactions.receipt.pdf', ['transaction' => $lastTransaction['id'], 'unduh' => 1]) }}" class="font-sans text-emerald-700 hover:underline">unduh PDF</a>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs font-medium text-emerald-800">{{ isset($lastTransaction['balance_after']) ? 'Sisa saldo' : 'Kembalian' }}</p>
                    <p class="text-xl font-bold text-emerald-900 tabular-nums">{{ $rp($lastTransaction['balance_after'] ?? $lastTransaction['change']) }}</p>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto bg-stone-100 px-5 py-4">
                <div class="mx-auto w-[58mm] max-w-full rounded-sm bg-white p-3 shadow-sm ring-1 ring-stone-200">
                    @include('livewire.kasir.partials.receipt', ['tx' => $lastTransaction, 'profile' => app(\App\Services\ReceiptPresenter::class)->profile()])
                </div>
            </div>

            <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                <button type="button" x-on:click="window.print()"
                        class="flex flex-1 items-center justify-center gap-2 rounded-xl border border-stone-300 py-3 text-sm font-semibold text-stone-800 hover:bg-stone-50">
                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 9V3h12v6M6 18H4a1 1 0 01-1-1v-6a2 2 0 012-2h14a2 2 0 012 2v6a1 1 0 01-1 1h-2M6 14h12v7H6z"/></svg>
                    Cetak Struk <span class="text-xs font-normal text-stone-500">(P)</span>
                </button>
                <button type="button" wire:click="newTransaction"
                        class="flex-1 rounded-xl bg-emerald-700 py-3 text-sm font-semibold text-white hover:bg-emerald-800">
                    Transaksi baru <span class="text-xs font-normal opacity-70">(Enter)</span>
                </button>
            </div>
        </div>
    </div>
@endif
