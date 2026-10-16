@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $st = $this->stats;
    $m = $this->manual;
    $promoClass = \App\Livewire\Promo\Index::class;
    $statusBadge = [
        'aktif' => ['Berlaku', 'bg-emerald-100 text-emerald-800'],
        'terjadwal' => ['Terjadwal', 'bg-sky-100 text-sky-800'],
        'berakhir' => ['Berakhir', 'bg-stone-100 text-stone-600'],
        'nonaktif' => ['Nonaktif', 'bg-stone-100 text-stone-500'],
        'habis' => ['Kuota habis', 'bg-red-100 text-red-800'],
    ];
    $typeIcon = [
        'persen' => 'M19 5 5 19M6.5 9a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM17.5 20a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
        'nominal' => 'M3 6h18v12H3zM12 15a3 3 0 100-6 3 3 0 000 6zM6 9v.01M18 15v.01',
        'beli_gratis' => 'M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H8a2 2 0 110-4c3 0 4 4 4 4zm0 0h4a2 2 0 100-4c-3 0-4 4-4 4z',
        'grosir' => 'M20 7l-8-4-8 4m16 0v10l-8 4m8-14l-8 4m0 10l-8-4V7m8 14V11',
    ];
    $manage = $this->canManage;
    $act = 'rounded-lg border border-stone-300 px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Diskon & Promo</h1>
                <p class="text-xs text-stone-500">Promo otomatis di kasir, kupon & voucher, dan batas diskon manual.</p>
            </div>
            @if ($this->canManage)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('promo.kupon.buat') }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Buat kupon</a>
                    <a href="{{ route('promo.buat') }}" wire:navigate class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Buat promo</a>
                </div>
            @endif
        </div>
        <x-promo.nav active="promo" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">

        {{-- Angka utama --}}
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Ringkasan promo">
            <div class="col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 lg:col-span-1">
                <p class="text-xs text-emerald-800">Promo berlaku hari ini</p>
                <p class="text-2xl font-bold text-emerald-900 tabular-nums">{{ $st['active_promos'] }}</p>
                <p class="text-[11px] text-emerald-800/80">{{ $st['scheduled_promos'] }} promo terjadwal menyusul</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Kupon aktif</p>
                <p class="text-xl font-bold tabular-nums">{{ $st['active_coupons'] }}</p>
                <p class="text-[11px] text-stone-500">{{ $st['coupon_uses'] }} kali dipakai</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Transaksi berpromo</p>
                <p class="text-xl font-bold tabular-nums">{{ number_format($st['promo_uses'], 0, ',', '.') }}</p>
                <p class="text-[11px] text-stone-500">Sejak promo dimulai</p>
            </div>
            <div class="col-span-2 rounded-xl border border-stone-200 bg-white px-4 py-3 lg:col-span-1">
                <p class="text-xs text-stone-500">Total potongan diberikan</p>
                <p class="text-xl font-bold tabular-nums">{{ $rp($st['discount_given']) }}</p>
                <p class="text-[11px] text-stone-500">Promo otomatis + diskon manual</p>
            </div>
        </section>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            {{-- Promo otomatis --}}
            <section aria-labelledby="promo-list">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="promo-list" class="text-sm font-semibold text-stone-700">Promo otomatis</h2>
                    <label class="relative block w-full sm:w-60">
                        <span class="sr-only">Cari promo</span>
                        <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari promo atau barang…"
                               class="w-full rounded-lg border border-stone-300 bg-white py-1.5 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                    </label>
                </div>
                <div class="mb-3 flex flex-wrap gap-1" role="group" aria-label="Status promo">
                    @foreach ($promoClass::STATUSES as $key => $label)
                        <button type="button" wire:click="setStatus('{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}"
                                @class(['rounded-full border px-3 py-1 text-sm font-medium',
                                        'border-emerald-700 bg-emerald-800 text-white' => $status === $key,
                                        'border-stone-300 bg-white text-stone-600 hover:border-stone-400' => $status !== $key])>
                            {{ $label }} <span @class(['tabular-nums', 'text-emerald-200' => $status === $key, 'text-stone-400' => $status !== $key])>{{ $this->counts[$key] }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="space-y-3" wire:loading.class="opacity-60" wire:target="setStatus,search">
                    @forelse ($this->promos as $p)
                        @php [$sLabel, $sClass] = $statusBadge[$p['status']]; $left = $promoClass::daysLeft($p); @endphp
                        <article class="rounded-xl border border-stone-200 bg-white p-4" wire:key="promo-{{ $p['id'] }}">
                            <div class="flex items-start gap-3">
                                <span @class(['grid size-10 shrink-0 place-items-center rounded-xl', 'bg-emerald-100 text-emerald-800' => $p['status'] === 'aktif', 'bg-stone-100 text-stone-500' => $p['status'] !== 'aktif'])>
                                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $typeIcon[$p['type']] }}"/></svg>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <h3 class="font-semibold">{{ $p['name'] }}</h3>
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $sClass }}">{{ $sLabel }}</span>
                                    </div>
                                    <p class="mt-0.5 text-sm text-stone-700">{{ $promoClass::describe($p) }}</p>
                                    <p class="mt-1 text-xs text-stone-500">
                                        {{ \Illuminate\Support\Carbon::parse($p['starts_at'])->translatedFormat('d M') }} – {{ \Illuminate\Support\Carbon::parse($p['ends_at'])->translatedFormat('d M Y') }}
                                        · {{ $promoClass::schedule($p) }}@if ($left) · <span class="font-medium text-stone-700">{{ $left }}</span>@endif
                                    </p>
                                </div>
                                <div class="hidden shrink-0 text-right sm:block">
                                    <p class="text-sm font-semibold tabular-nums">{{ $rp($p['discount_given']) }}</p>
                                    <p class="text-[11px] text-stone-500">{{ $p['usage_count'] }}× dipakai</p>
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-stone-500 sm:hidden">{{ $p['usage_count'] }}× dipakai · potongan {{ $rp($p['discount_given']) }}</p>
                            @if ($manage)
                                <div class="mt-3 flex flex-wrap justify-end gap-2 border-t border-stone-100 pt-3">
                                    <button type="button" wire:click="togglePromo({{ $p['id'] }})" wire:loading.attr="disabled" class="{{ $act }}">{{ $p['is_active'] ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                    <a href="{{ route('promo.ubah', $p['id']) }}" wire:navigate class="{{ $act }}">Ubah</a>
                                </div>
                            @endif
                        </article>
                    @empty
                        <p class="rounded-xl border border-dashed border-stone-300 px-4 py-8 text-center text-sm text-stone-400">
                            {{ $search !== '' ? 'Tidak ada promo yang cocok dengan "'.$search.'".' : 'Belum ada promo dengan status ini.' }}
                        </p>
                    @endforelse
                </div>
            </section>

            <div class="space-y-5">
                {{-- Diskon manual kasir --}}
                <section class="rounded-xl border border-stone-200 bg-white p-4" aria-labelledby="manual-title">
                    <div class="flex items-start justify-between gap-2">
                        <h2 id="manual-title" class="text-sm font-semibold">Diskon manual kasir</h2>
                        <span @class(['rounded-full px-2 py-0.5 text-[11px] font-semibold', 'bg-emerald-100 text-emerald-800' => $m['enabled'], 'bg-stone-100 text-stone-500' => ! $m['enabled']])>{{ $m['enabled'] ? 'Diizinkan' : 'Dimatikan' }}</span>
                    </div>
                    <dl class="mt-3 space-y-1.5 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-stone-500">Batas per transaksi</dt><dd class="font-medium tabular-nums">{{ $m['max_percent'] }}% · maks. {{ $rp($m['max_amount']) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-stone-500">Alasan</dt><dd class="font-medium">{{ $m['reason_required'] ? 'Wajib diisi' : 'Opsional' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-stone-500">7 hari terakhir</dt><dd class="font-medium tabular-nums">{{ $m['used_7_days'] }}× · {{ $rp($m['given_7_days']) }}</dd></div>
                    </dl>
                    <p class="mt-3 text-xs text-stone-500">Potongan di atas batas ini perlu persetujuan admin.@if (\App\Support\Menu::canReach('pengaturan.diskon')) <a href="{{ route('pengaturan.diskon') }}" wire:navigate class="font-medium text-emerald-800 hover:underline">Atur batas</a>@endif</p>
                </section>

                {{-- Kupon & voucher --}}
                <section class="rounded-xl border border-stone-200 bg-white" aria-labelledby="coupon-title">
                    <h2 id="coupon-title" class="px-4 pt-4 text-sm font-semibold">Kupon & voucher</h2>
                    <ul class="mt-2 divide-y divide-stone-100">
                        @if ($this->coupons === [])
                            <li class="px-4 pt-1 pb-4 text-sm text-stone-400">Belum ada kupon.</li>
                        @endif
                        @foreach ($this->coupons as $c)
                            @php [$cLabel, $cClass] = $statusBadge[$c['status']]; $pct = $c['quota'] ? min(100, round($c['used'] / $c['quota'] * 100)) : null; $left = $promoClass::daysLeft($c); @endphp
                            <li class="px-4 py-3" wire:key="coupon-{{ $c['id'] }}">
                                <div class="flex items-center justify-between gap-2">
                                    <code class="rounded-md border border-dashed border-emerald-400 bg-emerald-50 px-2 py-0.5 font-mono text-sm font-bold tracking-wide text-emerald-900">{{ $c['code'] }}</code>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $cClass }}">{{ $cLabel }}</span>
                                </div>
                                <p class="mt-1 text-sm text-stone-700">{{ $c['name'] }}</p>
                                <p class="text-xs text-stone-500">
                                    {{ $c['type'] === 'persen' ? $c['value'].'%'.($c['max_discount'] ? ' (maks. '.$rp($c['max_discount']).')' : '') : $rp($c['value']) }}
                                    @if ($c['min_purchase']) · min. belanja {{ $rp($c['min_purchase']) }}@endif
                                    @if ($left) · {{ $left }}@endif
                                </p>
                                <div class="mt-1.5 flex items-center gap-2">
                                    @if ($pct !== null)
                                        <div class="h-1.5 flex-1 rounded-full bg-stone-100" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $c['quota'] }}" aria-valuenow="{{ $c['used'] }}" aria-label="Kuota {{ $c['code'] }}">
                                            <div @class(['h-full rounded-full', 'bg-red-500' => $pct >= 100, 'bg-amber-500' => $pct >= 75 && $pct < 100, 'bg-emerald-600' => $pct < 75]) style="width: {{ $pct }}%"></div>
                                        </div>
                                        <span class="shrink-0 text-[11px] text-stone-500 tabular-nums">{{ $c['used'] }}/{{ $c['quota'] }}</span>
                                    @else
                                        <span class="text-[11px] text-stone-500">{{ $c['used'] }}× dipakai · tanpa batas kuota</span>
                                    @endif
                                </div>
                                @if ($manage)
                                    <div class="mt-2 flex justify-end gap-2">
                                        <button type="button" wire:click="toggleCoupon({{ $c['id'] }})" wire:loading.attr="disabled" class="{{ $act }}">{{ $c['is_active'] ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                        <a href="{{ route('promo.kupon.ubah', $c['id']) }}" wire:navigate class="{{ $act }}">Ubah</a>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>
        </div>
    </div>
</div>
