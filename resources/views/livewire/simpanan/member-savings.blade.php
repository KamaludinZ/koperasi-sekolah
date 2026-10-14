{{-- Simpanan per jenis (pokok/wajib/sukarela) di profil anggota — terpisah dari saldo tabungan. --}}
@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $sv = $this->summary;
    $sel = $this->selected;
    $monthly = $sel && $mode === 'setor' && $sel['period'] === 'bulanan' && $sel['amount'] > 0;
    $field = fn ($f) => 'w-full rounded-lg border bg-white px-3 py-2 text-sm outline-none focus:ring-4 '.($errors->has($f) ? 'border-red-400 focus:ring-red-500/15' : 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15');
@endphp
<section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="sv-title" data-saving-types>
    <div class="flex items-baseline justify-between gap-2">
        <h2 id="sv-title" class="font-semibold">Simpanan</h2>
        <p class="text-sm text-stone-500">Total <strong class="text-stone-900 tabular-nums">{{ $rp($sv['total']) }}</strong></p>
    </div>
    @if ($sv['types'])
        <ul class="mt-3 divide-y divide-stone-100">
            @foreach ($sv['types'] as $t)
                <li class="py-2.5" wire:key="sv-{{ $t['id'] }}">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="min-w-0 text-sm font-medium">{{ $t['name'] }}@unless ($t['is_active']) <span class="text-xs font-normal text-stone-400">(nonaktif)</span>@endunless</p>
                        <p class="shrink-0 font-semibold tabular-nums">{{ $rp($t['balance']) }}</p>
                    </div>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-stone-500">
                        @if ($t['status'] === 'lunas')
                            <span class="rounded bg-emerald-100 px-1.5 py-0.5 font-medium text-emerald-800">{{ $t['period'] === 'bulanan' ? 'Lancar' : 'Lunas' }}</span>
                        @elseif ($t['status'] === 'belum')
                            <span class="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-900">Belum dibayar {{ $rp(max(0, $t['amount'] - $t['balance'])) }}</span>
                        @elseif ($t['status'] === 'tunggak')
                            <span class="rounded bg-red-100 px-1.5 py-0.5 font-medium text-red-800">Tunggakan {{ $t['arrears_months'] }} bulan · {{ $rp($t['arrears_amount']) }}</span>
                        @endif
                        <span>{{ $t['withdrawable'] ? 'Boleh ditarik' : 'Ditahan selama anggota' }}</span>
                        @if ($t['last_at'])<span>· mutasi {{ \Illuminate\Support\Carbon::parse($t['last_at'])->diffForHumans() }}</span>@endif
                        @if ($this->canManage)
                            <span class="ml-auto flex gap-2">
                                @if ($t['is_active'])<button type="button" wire:click="openForm('setor', {{ $t['id'] }})" class="font-semibold text-emerald-800 hover:underline">Setor</button>@endif
                                @if ($t['withdrawable'] && $t['balance'] > 0)<button type="button" wire:click="openForm('tarik', {{ $t['id'] }})" class="font-semibold text-stone-700 hover:underline">Tarik</button>@endif
                            </span>
                        @endif
                    </p>
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-2 text-sm text-stone-500">Belum ada jenis simpanan aktif.</p>
    @endif
    <p class="mt-2 flex flex-wrap gap-x-4 text-xs font-medium">
        <a href="{{ route('simpanan.mutasi', ['anggota' => $memberId, 'periode' => 'semua']) }}" wire:navigate class="text-emerald-800 hover:underline">Riwayat mutasi →</a>
        <a href="{{ route('simpanan') }}" wire:navigate class="text-stone-500 hover:underline">Jenis simpanan</a>
    </p>

    @if ($open)
        <div class="fixed inset-0 z-50 grid place-items-center bg-stone-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="sv-form-title"
             x-data x-on:keydown.escape.window="$wire.close()">
            <form wire:submit="save" class="w-full max-w-md rounded-2xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                    <h3 id="sv-form-title" class="font-semibold">{{ $mode === 'setor' ? 'Setor simpanan' : 'Tarik simpanan' }}</h3>
                    <button type="button" wire:click="close" class="text-stone-400 hover:text-stone-600" aria-label="Tutup"><svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
                </div>
                <div class="space-y-4 px-5 py-5 text-sm">
                    <div class="grid grid-cols-2 gap-1 rounded-xl bg-stone-100 p-1" role="tablist" aria-label="Jenis mutasi">
                        @foreach (['setor' => 'Setor', 'tarik' => 'Tarik'] as $k => $label)
                            <button type="button" role="tab" wire:click="openForm('{{ $k }}', {{ $typeId ?? 'null' }})" aria-selected="{{ $mode === $k ? 'true' : 'false' }}"
                                    @class(['rounded-lg py-1.5 font-medium', 'bg-white text-emerald-900 shadow-sm' => $mode === $k, 'text-stone-600' => $mode !== $k])>{{ $label }}</button>
                        @endforeach
                    </div>
                    <label class="block"><span class="mb-1 block font-medium">Jenis simpanan</span>
                        <select wire:model.live="typeId" class="{{ $field('typeId') }}">
                            @foreach ($sv['types'] as $t)
                                @if ($mode === 'setor' ? $t['is_active'] : ($t['withdrawable'] && $t['balance'] > 0))
                                    <option value="{{ $t['id'] }}">{{ $t['name'] }} · saldo {{ $rp($t['balance']) }}</option>
                                @endif
                            @endforeach
                        </select>
                        @error('typeId') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    @if (! $sel)
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-amber-900">{{ $mode === 'tarik' ? 'Tidak ada simpanan yang bisa ditarik.' : 'Tidak ada jenis simpanan aktif.' }}</p>
                    @elseif ($monthly)
                        <label class="block"><span class="mb-1 block font-medium">Jumlah bulan</span>
                            <input type="number" min="1" max="{{ \App\Services\MemberSavingService::MAX_MONTHS }}" wire:model.live.debounce.300ms="months" class="{{ $field('months') }} w-28 tabular-nums">
                            @error('months') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <p class="rounded-lg bg-stone-50 px-3 py-2 text-stone-700" data-periods>
                            {{ $months }} × {{ $rp($sel['amount']) }} = <strong class="tabular-nums">{{ $rp($months * $sel['amount']) }}</strong>
                            <span class="block text-xs text-stone-500">Untuk bulan: {{ implode(', ', $this->periods) }}@if ($sel['arrears_months']) · tunggakan dibayar lebih dulu @endif</span>
                        </p>
                    @else
                        <label class="block"><span class="mb-1 block font-medium">Jumlah (Rp)</span>
                            <input type="number" min="1000" step="500" wire:model="amount" class="{{ $field('amount') }} tabular-nums" @if ($mode === 'tarik') max="{{ $sel['balance'] }}" @endif>
                            @if ($mode === 'tarik')<span class="mt-1 block text-xs text-stone-500">Saldo {{ $rp($sel['balance']) }}</span>
                            @elseif ($sel['period'] === 'sekali' && $sel['amount'])<span class="mt-1 block text-xs text-stone-500">Sisa yang belum dibayar {{ $rp(max(0, $sel['amount'] - $sel['balance'])) }} · boleh dicicil</span>@endif
                            @error('amount') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    @endif
                    <label class="block"><span class="mb-1 block font-medium">Catatan <span class="font-normal text-stone-500">(opsional)</span></span>
                        <input type="text" wire:model="note" maxlength="150" class="{{ $field('note') }}">
                        @error('note') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>
                <div class="flex justify-end gap-2 border-t border-stone-200 px-5 py-3">
                    <button type="button" wire:click="close" class="rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100">Batal</button>
                    <button type="submit" @disabled(! $sel) wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                        {{ $mode === 'setor' ? 'Simpan setoran' : 'Simpan penarikan' }}</button>
                </div>
            </form>
        </div>
    @endif
</section>
