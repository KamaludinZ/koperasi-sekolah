@use('App\Livewire\Pengadaan\Payables')
@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $date = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y');
    $s = $this->summary;
    $field = fn ($f) => 'w-full rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-4 '.($errors->has($f) ? 'border-red-400 focus:ring-red-500/15' : 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15');
    $statusTone = ['belum' => ['Belum dibayar', 'bg-stone-200 text-stone-700'], 'sebagian' => ['Dibayar sebagian', 'bg-amber-100 text-amber-900'], 'lunas' => ['Lunas', 'bg-emerald-100 text-emerald-800']];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Pengadaan Barang</h1>
                <p class="text-xs text-stone-500">Hutang pembelian ke supplier, jatuh tempo, dan pembayarannya.</p>
            </div>
            <div class="flex items-center gap-2">
                @can('kelola-pengadaan')
                <a href="{{ route('pengadaan.hutang.bayar', $supplier !== '' ? ['supplier' => $supplier] : []) }}" wire:navigate class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Bayar hutang</a>
                @endcan
            </div>
        </div>
        <x-pengadaan.nav active="pengadaan.hutang" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Sisa hutang</p><p class="text-2xl font-bold tabular-nums">{{ $rp($s['outstanding']) }}</p></div>
            <button type="button" wire:click="setFilter('lewat')" @class(['rounded-xl border px-4 py-3 text-left', 'border-red-200 bg-red-50 hover:border-red-400' => $s['overdue'] > 0, 'border-stone-200 bg-white' => $s['overdue'] === 0])>
                <p class="text-xs text-stone-500">Lewat jatuh tempo</p><p @class(['text-2xl font-bold tabular-nums', 'text-red-700' => $s['overdue'] > 0])>{{ $rp($s['overdue']) }}</p></button>
            <button type="button" wire:click="setFilter('minggu-ini')" @class(['rounded-xl border px-4 py-3 text-left', 'border-amber-200 bg-amber-50 hover:border-amber-400' => $s['week'] > 0, 'border-stone-200 bg-white' => $s['week'] === 0])>
                <p class="text-xs text-stone-500">Jatuh tempo ≤ 7 hari</p><p class="text-2xl font-bold tabular-nums">{{ $rp($s['week']) }}</p></button>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Dibayar bulan ini</p><p class="text-2xl font-bold text-emerald-800 tabular-nums">{{ $rp($s['paid_month']) }}</p></div>
        </div>

        <div class="flex flex-col gap-2 2xl:flex-row 2xl:items-center">
            <div class="flex w-fit max-w-full gap-1 overflow-x-auto rounded-xl bg-stone-200/60 p-1" role="tablist" aria-label="Status hutang">
                @foreach (Payables::FILTERS as $key => $label)
                    <button type="button" role="tab" wire:click="setFilter('{{ $key }}')" aria-selected="{{ $filter === $key ? 'true' : 'false' }}"
                            @class(['flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $filter === $key, 'text-stone-600 hover:text-stone-900' => $filter !== $key])>
                        {{ $label }} <span class="rounded-full bg-stone-100 px-1.5 text-[11px] text-stone-500 tabular-nums">{{ $this->counts[$key] }}</span>
                    </button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2 2xl:ml-auto">
                <select wire:model.live="supplier" aria-label="Supplier" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                    <option value="">Semua supplier</option>
                    @foreach ($this->supplierOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <input type="date" wire:model.live="from" aria-label="Jatuh tempo dari" class="rounded-lg border border-stone-300 bg-white px-2 py-1.5 text-sm">
                <span class="text-stone-400">–</span>
                <input type="date" wire:model.live="to" aria-label="Jatuh tempo sampai" class="rounded-lg border border-stone-300 bg-white px-2 py-1.5 text-sm">
                @if ($filter !== 'belum-lunas' || $supplier !== '' || $from !== '' || $to !== '')
                    <button type="button" wire:click="resetFilters" class="px-2 text-sm font-medium text-stone-500 hover:text-stone-800">Reset</button>
                @endif
            </div>
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar hutang">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[50rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr><th class="px-4 py-2 font-medium">Supplier</th><th class="px-3 py-2 font-medium">Status</th><th class="px-3 py-2 text-right font-medium">Tagihan</th><th class="px-3 py-2 text-right font-medium">Sisa</th><th class="px-3 py-2 font-medium">Jatuh tempo</th><th class="px-4 py-2"><span class="sr-only">Aksi</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($this->payables as $p)
                            @php [$label, $tone] = $statusTone[$p['status']]; $days = Payables::daysLeft($p); $open = $p['status'] !== 'lunas'; @endphp
                            <tr wire:key="ap-{{ $p['id'] }}" class="hover:bg-stone-50">
                                <td class="px-4 py-2.5"><span class="block font-medium">{{ $p['supplier'] }}</span><span class="font-mono text-xs text-stone-500">{{ $p['po_number'] }}</span></td>
                                <td class="px-3 py-2.5"><span class="rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $tone }}">{{ $label }}</span></td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($p['amount']) }}</td>
                                <td @class(['px-3 py-2.5 text-right font-semibold tabular-nums', 'text-stone-400' => ! $open])>{{ $open ? $rp($p['amount'] - $p['paid']) : '—' }}</td>
                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    {{ $date($p['due_at']) }}
                                    @if ($open)
                                        <span @class(['block text-xs', 'font-semibold text-red-700' => $days < 0, 'font-semibold text-amber-800' => $days >= 0 && $days <= 7, 'text-stone-500' => $days > 7])>{{ $days < 0 ? 'lewat '.abs($days).' hari' : ($days === 0 ? 'hari ini' : $days.' hari lagi') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    <button type="button" wire:click="showHistory({{ $p['id'] }})" class="rounded-lg px-2 py-1 text-sm font-medium text-stone-600 hover:bg-stone-100">Riwayat</button>
                                    @if ($open && $this->canPay)<button type="button" wire:click="pay({{ $p['id'] }})" class="rounded-lg border border-emerald-700 px-3 py-1 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">Bayar</button>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-stone-400">Tidak ada hutang pada saringan ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- Bayar --}}
    @if ($p = $this->paying)
        <div class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="pay-title" x-data x-on:keydown.escape.window="$wire.cancelPay()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="cancelPay"></div>
            <form wire:submit="savePayment" class="relative w-full max-w-md space-y-3 rounded-2xl bg-white p-5 shadow-xl" novalidate>
                <div>
                    <h2 id="pay-title" class="font-semibold">Bayar hutang ke {{ $p['supplier'] }}</h2>
                    <p class="text-sm text-stone-600"><span class="font-mono text-xs">{{ $p['po_number'] }}</span> · sisa <span class="font-semibold">{{ $rp($p['amount'] - $p['paid']) }}</span></p>
                </div>
                <label class="block text-sm"><span class="mb-1 block font-medium">Jumlah dibayar</span>
                    <input type="number" min="1" step="1000" wire:model="amount" value="{{ $amount }}" class="{{ $field('amount') }} tabular-nums">
                    @error('amount') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm"><span class="mb-1 block font-medium">Tanggal</span>
                        <input type="date" wire:model="paidAt" value="{{ $paidAt }}" max="{{ now()->toDateString() }}" class="{{ $field('paidAt') }}">
                        @error('paidAt') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <fieldset class="text-sm"><legend class="mb-1 font-medium">Cara bayar</legend>
                        <div class="flex gap-2">
                            @foreach (Payables::METHODS as $key => $label)
                                <label class="cursor-pointer rounded-full border border-stone-300 px-3 py-1.5 has-checked:border-emerald-700 has-checked:bg-emerald-900 has-checked:text-white">
                                    <input type="radio" wire:model.live="method" value="{{ $key }}" class="sr-only" @checked($method === $key)> {{ $label }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
                @if ($method === 'transfer')
                    <label class="block text-sm"><span class="mb-1 block font-medium">No. bukti transfer <span class="font-normal text-stone-500">(opsional)</span></span>
                        <input type="text" wire:model="reference" value="{{ $reference }}" maxlength="50" class="{{ $field('reference') }}"></label>
                @endif
                <label class="block text-sm"><span class="mb-1 block font-medium">Catatan <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="text" wire:model="note" value="{{ $note }}" maxlength="200" class="{{ $field('note') }}"></label>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" wire:click="cancelPay" class="rounded-xl px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100">Batal</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="savePayment" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Simpan pembayaran</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Riwayat pembayaran --}}
    @if ($h = $this->history)
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="hist-title" x-data x-on:keydown.escape.window="$wire.closeHistory()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="closeHistory"></div>
            <div class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
                    <div><p class="font-mono text-xs text-stone-500">{{ $h['po_number'] }}</p><h2 id="hist-title" class="font-semibold">{{ $h['supplier'] }}</h2></div>
                    <button type="button" wire:click="closeHistory" class="text-stone-400 hover:text-stone-600" aria-label="Tutup"><svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
                </div>
                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5 text-sm">
                    <dl class="grid grid-cols-[8rem_1fr] gap-y-1.5">
                        <dt class="text-stone-500">Tagihan</dt><dd class="tabular-nums">{{ $rp($h['amount']) }}</dd>
                        <dt class="text-stone-500">Sudah dibayar</dt><dd class="tabular-nums">{{ $rp($h['paid']) }}</dd>
                        <dt class="text-stone-500">Sisa</dt><dd class="font-semibold tabular-nums">{{ $rp($h['amount'] - $h['paid']) }}</dd>
                        <dt class="text-stone-500">Jatuh tempo</dt><dd>{{ $date($h['due_at']) }}</dd>
                    </dl>
                    <ul class="divide-y divide-stone-100 rounded-lg border border-stone-200">
                        @forelse ($h['payments'] as $x)
                            <li class="px-3 py-2">
                                <p class="flex justify-between"><span class="font-semibold tabular-nums">{{ $rp($x['amount']) }}</span><span class="text-xs text-stone-500">{{ $date($x['paid_at']) }}</span></p>
                                <p class="text-xs text-stone-500">{{ Payables::METHODS[$x['method']] }}@if ($x['reference']) · {{ $x['reference'] }}@endif · {{ $x['user'] }}@if ($x['note']) · {{ $x['note'] }}@endif</p>
                                @if ($this->isAdmin)
                                    @if ($cancelPaymentId === $x['id'])
                                        <div class="mt-2 space-y-1.5">
                                            <input type="text" wire:model="cancelPaymentReason" maxlength="200" placeholder="Alasan pembatalan" class="w-full rounded-lg border border-stone-300 px-2.5 py-1.5 text-xs">
                                            @error('cancelPaymentReason') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                            <div class="flex justify-end gap-2">
                                                <button type="button" wire:click="$set('cancelPaymentId', null)" class="rounded px-2 py-1 text-xs text-stone-600 hover:bg-stone-100">Batal</button>
                                                <button type="button" wire:click="cancelPayment" class="rounded bg-red-700 px-2 py-1 text-xs font-semibold text-white hover:bg-red-800">Batalkan pembayaran</button>
                                            </div>
                                        </div>
                                    @else
                                        <button type="button" wire:click="$set('cancelPaymentId', {{ $x['id'] }})" class="mt-1 text-xs font-medium text-red-700 hover:underline">Batalkan (salah input)</button>
                                    @endif
                                @endif
                            </li>
                        @empty
                            <li class="px-3 py-6 text-center text-xs text-stone-400">Belum ada pembayaran.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    @endif
</div>
