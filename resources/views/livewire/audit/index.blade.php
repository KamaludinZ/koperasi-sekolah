@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    $num = fn ($n) => number_format((int) $n, 0, ',', '.');
    $roles = \App\Models\Role::LABELS;
    $s = $this->summary;
    // Gaya per kategori: warna lencana + ikon (path SVG 24px).
    $cat = [
        'auth' => ['bg-sky-100 text-sky-800', 'M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3'],
        'transaksi' => ['bg-emerald-100 text-emerald-800', 'M3 3h18v4H3zM5 7v13h14V7M9 11h6'],
        'tabungan' => ['bg-amber-100 text-amber-900', 'M19 7h-1V5a2 2 0 00-2-2H5a2 2 0 00-2 2v12a3 3 0 003 3h13a2 2 0 002-2V9a2 2 0 00-2-2zM16 14h.01'],
        'kas' => ['bg-orange-100 text-orange-900', 'M3 7h18v12H3zM3 7l3-3h12l3 3M16 13h2M7 11v4'],
        'stok' => ['bg-violet-100 text-violet-800', 'M20 7l-8-4-8 4m16 0v10l-8 4m8-14l-8 4m0 10l-8-4V7m8 14V11'],
        'barang' => ['bg-teal-100 text-teal-800', 'M7 7h.01M3 11l8.6 8.6a2 2 0 002.8 0l5.2-5.2a2 2 0 000-2.8L11 3H3z'],
        'pengadaan' => ['bg-sky-100 text-sky-800', 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17'],
        'anggota' => ['bg-lime-100 text-lime-900', 'M17 20h5v-2a4 4 0 00-5-3.9M9 20H2v-2a4 4 0 014-4h3a4 4 0 014 4v2zM12 7a3 3 0 11-6 0 3 3 0 016 0z'],
        'pengguna' => ['bg-rose-100 text-rose-800', 'M12 15a3 3 0 100-6 3 3 0 000 6zM12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6z'],
        'laporan' => ['bg-stone-200 text-stone-700', 'M4 20V10m6 10V4m6 16v-7m4 7H2'],
    ];
    $danger = ['auth.failed', 'auth.locked', 'auth.inactive', 'member.deleted', 'user.deactivated', 'savings.withdraw', 'shift.denied'];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Audit & Riwayat</h1>
                <p class="text-xs text-stone-500">Jejak login, sirkulasi uang & barang, dan perubahan data oleh semua petugas. Riwayat tidak dapat diubah atau dihapus.</p>
            </div>
            <button type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export"
                    class="inline-flex items-center gap-2 rounded-xl border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-800 hover:bg-stone-50">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12m0 0-4-4m4 4 4-4M4 20h16"/></svg>
                Unduh CSV
            </button>
        </div>
        <x-audit.nav active="audit" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6">
        {{-- Tampilan & periode --}}
        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-200/60 p-1" role="tablist" aria-label="Jenis riwayat">
                @foreach (\App\Services\AuditQuery::VIEWS as $key => $label)
                    <button type="button" role="tab" wire:click="setView('{{ $key }}')" aria-selected="{{ $view === $key ? 'true' : 'false' }}"
                            @class(['shrink-0 rounded-lg px-3.5 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $view === $key, 'text-stone-600 hover:text-stone-900' => $view !== $key])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex gap-1" role="group" aria-label="Periode">
                    @foreach (\App\Services\AuditQuery::PERIODS as $key => $label)
                        @php $on = $period === $key && $from === '' && $to === ''; @endphp
                        <button type="button" wire:click="setPeriod('{{ $key }}')" aria-pressed="{{ $on ? 'true' : 'false' }}"
                                @class(['rounded-full px-3 py-1 text-sm font-medium', 'bg-emerald-900 text-white' => $on, 'border border-stone-300 bg-white text-stone-700 hover:border-stone-400' => ! $on])>{{ $label }}</button>
                    @endforeach
                </div>
                <input type="date" wire:model.live="from" max="{{ now()->toDateString() }}" aria-label="Dari tanggal" class="rounded-lg border border-stone-300 bg-white px-2 py-1 text-sm">
                <span class="text-stone-400">–</span>
                <input type="date" wire:model.live="to" max="{{ now()->toDateString() }}" aria-label="Sampai tanggal" class="rounded-lg border border-stone-300 bg-white px-2 py-1 text-sm">
            </div>
        </div>
        @error('to') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

        {{-- Ringkasan --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                ['Aktivitas tercatat', $num($s['total']), null, ''],
                ['Login berhasil', $num($s['logins']), null, ''],
                ['Login gagal', $num($s['failed_logins']), $s['failed_logins'] > 0 ? 'perlu diperhatikan' : null, $s['failed_logins'] > 0 ? 'border-red-200 bg-red-50' : ''],
                ['Penjualan', $num($s['sales']), $rp($s['sales_amount']), 'border-emerald-200 bg-emerald-50'],
                ['Tabungan masuk / keluar', $rp($s['deposits']), '− '.$rp($s['withdrawals']), ''],
                ['Ubah stok · cetak ulang', $num($s['stock_changes']).' · '.$num($s['reprints']), null, ''],
            ] as [$label, $value, $sub, $tone])
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3 {{ $tone }}">
                    <p class="text-xs text-stone-500">{{ $label }}</p>
                    <p class="text-xl font-bold tabular-nums">{{ $value }}</p>
                    @if ($sub)<p class="text-xs text-stone-500 tabular-nums">{{ $sub }}</p>@endif
                </div>
            @endforeach
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
            {{-- Linimasa --}}
            <section class="min-w-0 space-y-3" aria-label="Linimasa aktivitas">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <label class="relative flex-1">
                        <span class="sr-only">Cari aktivitas</span>
                        <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari invoice, nama barang/anggota, petugas…"
                               class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                    </label>
                    <select wire:model.live="category" aria-label="Kategori" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                        <option value="">Semua kategori</option>
                        @foreach (\App\Models\ActivityLog::CATEGORIES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="actor" aria-label="Petugas" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                        <option value="">Semua petugas</option>
                        @foreach ($this->actorOptions as $name)
                            <option value="{{ $name }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @if ($category !== '' || $actor !== '' || $search !== '' || $view !== 'semua')
                        <button type="button" wire:click="resetFilters" class="px-2 text-sm font-medium text-stone-500 hover:text-stone-800">Reset</button>
                    @endif
                </div>

                @forelse ($this->days as $date => $items)
                    <div wire:key="audit-day-{{ $date }}">
                        <h2 class="mb-1.5 text-sm font-semibold">
                            {{ \Illuminate\Support\Carbon::parse($date)->isToday() ? 'Hari ini' : \Illuminate\Support\Carbon::parse($date)->translatedFormat('l') }}
                            <span class="font-normal text-stone-500">· {{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('d F Y') }}</span>
                        </h2>
                        <ol class="divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 bg-white">
                            @foreach ($items as $log)
                                @php [$tone, $icon] = $cat[$log->category] ?? $cat['laporan']; $bad = in_array($log->event, $danger, true); @endphp
                                <li wire:key="log-{{ $log->id }}">
                                    <button type="button" wire:click="show({{ $log->id }})"
                                            @class(['flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-emerald-50/50 focus-visible:bg-emerald-50 focus-visible:outline-none', 'bg-red-50/40' => in_array($log->event, ['auth.failed', 'auth.locked'], true)])>
                                        <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg {{ $tone }}" title="{{ $log->category_label }}">
                                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span @class(['block text-sm', 'text-red-800' => $bad && $log->category === 'auth', 'text-stone-900' => ! ($bad && $log->category === 'auth')])>{{ $log->description }}</span>
                                            <span class="mt-0.5 block text-xs text-stone-500">
                                                <span class="font-medium text-stone-700">{{ $log->actor_name ?? 'Sistem' }}</span>@if ($log->actor_role) · {{ $roles[$log->actor_role] ?? $log->actor_role }}@endif
                                                · {{ $log->category_label }}@if ($log->ip_address) · {{ $log->ip_address }}@endif
                                            </span>
                                        </span>
                                        <span class="shrink-0 text-right">
                                            <span class="block text-xs text-stone-500 tabular-nums">{{ $log->created_at->format('H:i:s') }}</span>
                                            @if ($log->amount !== null)
                                                <span @class(['block text-sm font-semibold tabular-nums', 'text-red-700' => $log->event === 'savings.withdraw', 'text-emerald-800' => $log->event !== 'savings.withdraw'])>
                                                    {{ $log->event === 'savings.withdraw' ? '−' : '+' }}{{ $rp($log->amount) }}
                                                </span>
                                            @endif
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center">
                        <p class="text-sm font-medium text-stone-600">Belum ada aktivitas pada filter ini</p>
                        <button type="button" wire:click="resetFilters" class="mt-2 text-sm font-medium text-emerald-700 hover:underline">Tampilkan semua</button>
                    </div>
                @endforelse

                @if ($this->logs->hasPages())
                    @include('livewire.transaksi.partials.pagination', ['paginator' => $this->logs])
                @endif
            </section>

            {{-- Per petugas --}}
            <aside class="relative isolate h-fit overflow-hidden rounded-xl bg-emerald-950 p-4 text-emerald-50" aria-labelledby="actors-title">
                <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.07]" aria-hidden="true"></div>
                <h2 id="actors-title" class="font-display text-lg font-bold text-white">Aktivitas per petugas</h2>
                <p class="text-xs text-emerald-300">Pada periode terpilih. Klik untuk menyaring.</p>
                <ul class="mt-3 space-y-2">
                    @forelse ($this->actors as $a)
                        <li>
                            <button type="button" wire:click="filterActor(@js($a['name']))"
                                    @class(['w-full rounded-lg p-3 text-left transition', 'bg-emerald-700/70 ring-1 ring-amber-400/60' => $actor === $a['name'], 'bg-emerald-900/60 hover:bg-emerald-900' => $actor !== $a['name']])>
                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="truncate font-semibold text-white">{{ $a['name'] }}</span>
                                    <span class="shrink-0 text-xs text-amber-300">{{ $a['role'] ? ($roles[$a['role']] ?? $a['role']) : '—' }}</span>
                                </span>
                                <span class="mt-1 block text-xs text-emerald-200 tabular-nums">
                                    {{ $num($a['actions']) }} aktivitas
                                    @if ($a['sales']) · {{ $num($a['sales']) }} penjualan ({{ $rp($a['sales_amount']) }})@endif
                                </span>
                                <span class="block text-xs text-emerald-300/80">
                                    @if ($a['last_login']) Login terakhir {{ \Illuminate\Support\Carbon::parse($a['last_login'])->diffForHumans() }} @else Aktivitas terakhir {{ \Illuminate\Support\Carbon::parse($a['last_at'])->diffForHumans() }} @endif
                                </span>
                                @if ($a['failed'])
                                    <span class="mt-1 inline-block rounded bg-red-500/20 px-1.5 py-0.5 text-[11px] font-medium text-red-200">{{ $a['failed'] }}× gagal masuk</span>
                                @endif
                            </button>
                        </li>
                    @empty
                        <li class="rounded-lg bg-emerald-900/60 p-3 text-sm text-emerald-200">Belum ada aktivitas.</li>
                    @endforelse
                </ul>
            </aside>
        </div>
    </div>

    @include('livewire.audit.partials.detail')
</div>
