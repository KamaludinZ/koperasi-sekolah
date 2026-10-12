@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $s = $this->settings;
    $p = \App\Support\MockData::profile();
    $r = $s['receipt'];
    $t = $s['tax'];
    $done = collect($this->checklist)->where('done', true)->count();
    $total = count($this->checklist);
    $link = fn (string $route, string $label) => \Illuminate\Support\Facades\Route::has($route)
        ? '<a href="'.route($route).'" wire:navigate class="text-sm font-semibold text-emerald-800 hover:underline">'.e($label).' →</a>'
        : '<span class="text-sm text-stone-400" title="Segera hadir">'.e($label).' · segera</span>';
    $card = 'rounded-2xl border border-stone-200 bg-white p-5';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-lg font-semibold">Pengaturan Koperasi</h1>
                <p class="text-xs text-stone-500">
                    Identitas, struk, dan aturan dasar transaksi.
                    @if ($s['updated_at']) Terakhir diubah {{ \Illuminate\Support\Carbon::parse($s['updated_at'])->diffForHumans() }}@if ($s['updated_by']) oleh {{ $s['updated_by'] }}@endif. @endif

                </p>
            </div>
        </div>
        <x-pengaturan.nav />
    </header>

    <div class="grid gap-5 px-4 py-6 sm:px-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="grid min-w-0 gap-5 md:grid-cols-2">
            {{-- Profil --}}
            <section class="{{ $card }} md:col-span-2" aria-labelledby="set-profile">
                <div class="flex flex-wrap items-start gap-4">
                    @if ($p['logo'])
                        <img src="{{ $p['logo'] }}" alt="Logo {{ $p['name'] }}" class="size-16 rounded-xl object-cover ring-1 ring-stone-200">
                    @else
                        <span class="grid size-16 shrink-0 place-items-center rounded-xl bg-emerald-950" title="Belum ada logo — memakai lambang bawaan"><x-brand.mark class="size-10" /></span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold tracking-wide text-emerald-700 uppercase" id="set-profile">Profil koperasi</p>
                        <h2 class="font-display text-2xl font-bold text-emerald-950">{{ $p['name'] }}</h2>
                        <p class="text-sm text-stone-600">{{ $p['school'] }}</p>
                        <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                            <div class="flex gap-2"><dt class="text-stone-500">Alamat</dt><dd class="text-stone-800">{{ $p['address'] ?: '—' }}</dd></div>
                            <div class="flex gap-2"><dt class="text-stone-500">Telepon</dt><dd class="text-stone-800">{{ $p['phone'] ?: '—' }}</dd></div>
                            <div class="flex gap-2 sm:col-span-2"><dt class="text-stone-500">Motto</dt><dd class="text-stone-800 italic">{{ $p['motto'] ?: '—' }}</dd></div>
                        </dl>
                    </div>
                    <div class="shrink-0">{!! $link('pengaturan.profil', 'Ubah profil') !!}</div>
                </div>
            </section>

            {{-- Struk --}}
            <section class="{{ $card }} flex flex-col" aria-labelledby="set-receipt">
                <p class="text-xs font-semibold tracking-wide text-emerald-700 uppercase" id="set-receipt">Struk pembelian</p>
                <div class="mx-auto mt-3 w-full max-w-[58mm] rounded-sm border border-dashed border-stone-300 bg-stone-50 p-3 text-center font-mono text-[11px] leading-snug" aria-label="Contoh kepala & kaki struk">
                    <p class="font-bold uppercase">{{ $r['header'] ?: $p['name'] }}</p>
                    <p>{{ $r['subheader'] ?: $p['school'] }}</p>
                    <p class="my-1.5 border-t border-dashed border-stone-400"></p>
                    <p class="text-stone-400">… daftar barang …</p>
                    <p class="my-1.5 border-t border-dashed border-stone-400"></p>
                    <p>{{ $r['footer'] }}</p>
                </div>
                <ul class="mt-3 space-y-1 text-sm text-stone-600">
                    <li>Kertas {{ $r['paper'] }}</li>
                    <li>{{ $r['show_cashier'] ? 'Menampilkan' : 'Tanpa' }} nama kasir · {{ $r['show_member'] ? 'menampilkan' : 'tanpa' }} nama anggota</li>
                </ul>
                <div class="mt-auto pt-4">{!! $link('pengaturan.struk', 'Atur struk') !!}</div>
            </section>

            {{-- Pajak & pembulatan --}}
            <section class="{{ $card }} flex flex-col" aria-labelledby="set-tax">
                <p class="text-xs font-semibold tracking-wide text-emerald-700 uppercase" id="set-tax">Pajak & pembulatan</p>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-stone-500">Pajak penjualan</dt>
                        <dd class="font-semibold">{{ $t['enabled'] ? str_replace('.', ',', (string) ($t['rate'] + 0)).'%'.($t['included'] ? ' (sudah termasuk harga)' : '') : 'Tidak dipungut' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-stone-500">Pembulatan</dt>
                        <dd class="text-right font-semibold">{{ \App\Livewire\Pengaturan\Index::ROUNDING[$t['rounding']] }}@if ($t['rounding'] !== 'none') <span class="font-normal text-stone-500">ke {{ $rp($t['rounding_unit']) }}{{ $t['cash_only'] ? ', tunai saja' : '' }}</span>@endif</dd></div>
                </dl>
                @php $ex = $this->example; @endphp
                <div class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm">
                    <p class="text-xs font-medium text-emerald-800">Contoh belanja {{ $rp($ex['subtotal']) }}</p>
                    <div class="mt-1 flex justify-between"><span class="text-stone-600">Pajak</span><span class="tabular-nums">{{ $rp($ex['tax']) }}</span></div>
                    <div class="flex justify-between"><span class="text-stone-600">Total</span><span class="tabular-nums">{{ $rp($ex['total']) }}</span></div>
                    <div class="flex justify-between font-semibold text-emerald-900"><span>Dibayar pembeli</span><span class="tabular-nums">{{ $rp($ex['rounded']) }}</span></div>
                </div>
                <div class="mt-auto pt-4">{!! $link('pengaturan.pajak', 'Atur pajak & pembulatan') !!}</div>
            </section>

            {{-- Jam buka --}}
            <section class="{{ $card }}" aria-labelledby="set-hours">
                <p class="text-xs font-semibold tracking-wide text-emerald-700 uppercase" id="set-hours">Jam buka</p>
                <dl class="mt-3 divide-y divide-stone-100 text-sm">
                    @foreach ($p['hours'] as $h)
                        <div class="flex justify-between py-1.5"><dt class="text-stone-600">{{ $h['days'] }}</dt><dd class="font-medium tabular-nums">{{ $h['time'] }}</dd></div>
                    @endforeach
                    @if ($closed = \App\Support\OpeningHours::closedDays($p['schedule']))
                        <div class="flex justify-between py-1.5"><dt class="text-stone-600">{{ implode(', ', $closed) }} & libur</dt><dd class="text-stone-400">Tutup</dd></div>
                    @endif
                </dl>
                <p class="mt-3 text-xs text-stone-500">Ditampilkan di beranda publik. Diubah lewat profil koperasi.</p>
            </section>

            {{-- Stok --}}
            <section class="{{ $card }}" aria-labelledby="set-stock">
                <p class="text-xs font-semibold tracking-wide text-emerald-700 uppercase" id="set-stock">Stok barang</p>
                <p class="mt-3 text-sm text-stone-600">Batas minimum bawaan untuk barang baru</p>
                <p class="text-3xl font-bold tabular-nums">{{ $this->defaultMinStock }} <span class="text-base font-medium text-stone-500">pcs</span></p>
                <p class="mt-2 text-xs text-stone-500">Barang ditandai menipis bila stoknya sama dengan atau di bawah batas ini.</p>
                <a href="{{ route('barang') }}" wire:navigate class="mt-4 inline-block text-sm font-semibold text-emerald-800 hover:underline">Atur di Barang & Stok →</a>
            </section>
        </div>

        {{-- Kelengkapan --}}
        <aside class="relative isolate h-fit overflow-hidden rounded-2xl bg-emerald-950 p-5 text-emerald-50" aria-labelledby="set-checklist">
            <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.08]" aria-hidden="true"></div>
            <h2 id="set-checklist" class="font-display text-xl font-bold text-white">Kelengkapan profil</h2>
            <p class="text-sm text-emerald-200">{{ $done }} dari {{ $total }} sudah lengkap</p>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-emerald-900" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $done }}">
                <div class="h-full rounded-full bg-amber-400" style="width: {{ round($done / max(1, $total) * 100) }}%"></div>
            </div>
            <ul class="mt-4 space-y-2 text-sm">
                @foreach ($this->checklist as $item)
                    <li class="flex items-center gap-2.5">
                        @if ($item['done'])
                            <span class="grid size-5 place-items-center rounded-full bg-amber-400 text-emerald-950" aria-hidden="true"><svg class="size-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path d="m5 13 4 4L19 7"/></svg></span>
                            <span class="text-emerald-100">{{ $item['label'] }}</span>
                        @else
                            <span class="size-5 rounded-full border-2 border-emerald-600" aria-hidden="true"></span>
                            <span class="text-white">{{ $item['label'] }} <span class="text-xs text-amber-300">· belum</span></span>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 text-xs text-emerald-300">Profil yang lengkap tampil rapi di struk dan beranda publik koperasi.</p>
        </aside>
    </div>
</div>
