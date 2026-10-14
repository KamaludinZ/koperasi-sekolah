{{-- Panel geser: catat pengajuan pinjaman --}}
@if ($creating)
    @php
        $input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
        $rp = fn ($n) => \App\Support\Rupiah::format($n);
        $m = $this->selectedMember;
        $preview = $form->preview();
        $err = fn ($f) => $errors->first('form.'.$f);
    @endphp
    <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="loan-form-title"
         x-data x-on:keydown.escape.window="$wire.cancel()">
        <div class="absolute inset-0 bg-stone-950/50" wire:click="cancel"></div>

        <form wire:submit="save" class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <h2 id="loan-form-title" class="font-semibold">Catat pengajuan pinjaman</h2>
                <button type="button" wire:click="cancel" class="text-stone-400 hover:text-stone-600" aria-label="Tutup">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5">
                {{-- Anggota --}}
                <div>
                    <span class="mb-1 block text-sm font-medium">Anggota</span>
                    @if ($m)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2" data-selected-member="{{ $m['id'] }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold">{{ $m['name'] }}</p>
                                <p class="font-mono text-xs text-stone-600">{{ $m['member_code'] }}{{ $m['class'] ? ' · '.$m['class'] : '' }}</p>
                            </div>
                            <button type="button" wire:click="clearMember" class="text-sm font-medium text-emerald-800 hover:underline">Ganti</button>
                        </div>
                        @if ($m['loans']['open_count'] > 0)
                            <p @class(['mt-2 rounded-lg px-3 py-2 text-xs', 'bg-red-50 text-red-800' => $m['loans']['has_overdue'], 'bg-amber-50 text-amber-900' => ! $m['loans']['has_overdue']]) role="note">
                                Anggota ini masih punya {{ $m['loans']['open_count'] }} pinjaman terbuka ({{ implode(', ', $m['loans']['codes']) }}){{ $m['loans']['active_outstanding'] > 0 ? ', sisa '.$rp($m['loans']['active_outstanding']) : '' }}{{ $m['loans']['has_overdue'] ? ', termasuk yang menunggak' : '' }}.
                            </p>
                        @endif
                    @else
                        <div class="relative">
                            <input type="search" wire:model.live.debounce.250ms="memberSearch" placeholder="Cari nama atau kode anggota…" autocomplete="off" autofocus maxlength="100" class="{{ $input }}">
                            @if ($this->memberResults)
                                <ul class="mt-1 divide-y divide-stone-100 overflow-hidden rounded-lg border border-stone-200 bg-white shadow-sm">
                                    @foreach ($this->memberResults as $r)
                                        <li wire:key="pick-{{ $r['id'] }}">
                                            <button type="button" wire:click="selectMember({{ $r['id'] }})" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-stone-50">
                                                <span>{{ $r['name'] }}</span><span class="font-mono text-xs text-stone-500">{{ $r['member_code'] }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif (mb_strlen(trim($memberSearch)) >= 2)
                                <p class="mt-1 text-xs text-stone-500">Tidak ada anggota aktif yang cocok.</p>
                            @endif
                        </div>
                    @endif
                    @if ($err('member_id'))<span class="mt-1 block text-xs text-red-600">{{ $err('member_id') }}</span>@endif
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <label class="col-span-2 block">
                        <span class="mb-1 block text-sm font-medium">Nominal pinjaman</span>
                        <span class="relative block">
                            <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-stone-500">Rp</span>
                            <input type="number" inputmode="numeric" min="0" step="1000" wire:model.live.debounce.400ms="form.principal" class="{{ $input }} pl-9 tabular-nums">
                        </span>
                        @if ($err('principal'))<span class="mt-1 block text-xs text-red-600">{{ $err('principal') }}</span>@endif
                    </label>
                    <div class="col-span-2">
                        <div class="mb-1 flex items-center justify-between">
                            <span class="text-sm font-medium">Bunga</span>
                            <span class="inline-flex rounded-lg bg-stone-100 p-0.5 text-xs" role="radiogroup" aria-label="Cara isi bunga">
                                @foreach (['nominal' => 'Rupiah', 'persen' => 'Persen'] as $mode => $label)
                                    <label @class(['cursor-pointer rounded-md px-2 py-1', 'bg-white font-semibold shadow-sm' => $form->interest_mode === $mode])>
                                        <input type="radio" wire:model.live="form.interest_mode" value="{{ $mode }}" class="sr-only">{{ $label }}
                                    </label>
                                @endforeach
                            </span>
                        </div>
                        @if ($form->interest_mode === 'persen')
                            <span class="relative block">
                                <input type="number" inputmode="decimal" min="0" max="100" step="0.01" wire:model.live.debounce.400ms="form.interest_rate" placeholder="Mis. 5" aria-label="Persen bunga dari pokok" class="{{ $input }} pr-24 tabular-nums">
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-stone-500">% dari pokok</span>
                            </span>
                            @if ($err('interest_rate'))<span class="mt-1 block text-xs text-red-600">{{ $err('interest_rate') }}</span>@else<span class="mt-1 block text-xs text-stone-500" data-interest-converted>= {{ $rp($preview['interest']) }} untuk seluruh tenor</span>@endif
                        @else
                            <span class="relative block">
                                <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-stone-500">Rp</span>
                                <input type="number" inputmode="numeric" min="0" step="1000" wire:model.live.debounce.400ms="form.interest" placeholder="0" aria-label="Nominal bunga" class="{{ $input }} pl-9 tabular-nums">
                            </span>
                            <span class="mt-1 block text-xs text-stone-500">Sesuai kesepakatan untuk seluruh tenor; isi 0 bila tanpa bunga.</span>
                        @endif
                        @if ($err('interest'))<span class="mt-1 block text-xs text-red-600">{{ $err('interest') }}</span>@endif
                    </div>
                    <div class="col-span-2">
                        <span class="mb-1 block text-sm font-medium">Tenor</span>
                        <div class="flex gap-2">
                            <span class="relative block w-32 shrink-0">
                                <input type="number" inputmode="numeric" min="1" max="120" wire:model.live.debounce.400ms="form.tenor_months" aria-label="Tenor dalam bulan" class="{{ $input }} pr-14 tabular-nums">
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-stone-500">bulan</span>
                            </span>
                            <div class="flex flex-wrap gap-1" aria-label="Pilihan tenor cepat">
                                @foreach (\App\Livewire\Forms\LoanApplicationForm::TENOR_PRESETS as $t)
                                    <button type="button" wire:click="setTenor({{ $t }})" @class(['rounded-lg border px-2.5 py-1.5 text-xs font-medium tabular-nums', 'border-emerald-600 bg-emerald-50 text-emerald-900' => (int) $form->tenor_months === $t, 'border-stone-300 text-stone-600 hover:bg-stone-50' => (int) $form->tenor_months !== $t])>{{ $t }}</button>
                                @endforeach
                            </div>
                        </div>
                        @if ($err('tenor_months'))<span class="mt-1 block text-xs text-red-600">{{ $err('tenor_months') }}</span>@else<span class="mt-1 block text-xs text-stone-500">Bebas 1–120 bulan sesuai kesepakatan.</span>@endif
                    </div>
                </div>

                <div class="rounded-lg bg-stone-50 px-3 py-2 text-sm" data-preview>
                    <div class="flex justify-between"><span class="text-stone-500">Total dikembalikan</span><span class="font-semibold tabular-nums">{{ $rp($preview['total']) }}</span></div>
                    <div class="flex justify-between"><span class="text-stone-500">Angsuran ± per bulan</span><span class="font-semibold tabular-nums">{{ $rp($preview['monthly']) }}</span></div>
                    @if ($preview['rate'] > 0)
                        <p class="mt-1 text-xs text-stone-500" data-rate>Bunga setara {{ number_format($preview['rate'], 2, ',', '.') }}% dari pokok{{ $preview['rate_monthly'] > 0 ? ' (± '.number_format($preview['rate_monthly'], 2, ',', '.').'% per bulan)' : '' }}.</p>
                    @endif
                </div>

                <fieldset>
                    <legend class="mb-1 block text-sm font-medium">Metode bayar angsuran</legend>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (\App\Models\Loan::METHODS as $value => $label)
                            <label @class(['flex cursor-pointer items-center justify-center rounded-lg border px-2 py-2 text-sm', 'border-emerald-600 bg-emerald-50 font-semibold text-emerald-900' => $form->payment_method === $value, 'border-stone-300' => $form->payment_method !== $value])>
                                <input type="radio" wire:model.live="form.payment_method" value="{{ $value }}" class="sr-only"> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @if ($err('payment_method'))<span class="mt-1 block text-xs text-red-600">{{ $err('payment_method') }}</span>@endif
                </fieldset>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Keperluan <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="text" wire:model="form.purpose" maxlength="200" placeholder="Mis. biaya sekolah anak" class="{{ $input }}">
                    @if ($err('purpose'))<span class="mt-1 block text-xs text-red-600">{{ $err('purpose') }}</span>@endif
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Tanggal pengajuan</span>
                    <input type="date" wire:model="form.applied_at" max="{{ today()->toDateString() }}" class="{{ $input }}">
                    @if ($err('applied_at'))<span class="mt-1 block text-xs text-red-600">{{ $err('applied_at') }}</span>@endif
                </label>
            </div>

            <div class="flex gap-2 border-t border-stone-200 px-5 py-4">
                <button type="button" wire:click="cancel" class="rounded-xl border border-stone-300 px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="flex-1 rounded-xl bg-emerald-700 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Simpan pengajuan</button>
            </div>
        </form>
    </div>
@endif
