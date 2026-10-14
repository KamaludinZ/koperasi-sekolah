@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $s = $this->summary;
    $statusTone = ['disetor' => 'bg-emerald-100 text-emerald-800', 'belum' => 'bg-amber-100 text-amber-900', 'kosong' => 'bg-stone-100 text-stone-600'];
    $statusLabel = ['disetor' => 'Sudah disetor', 'belum' => 'Dicatat, belum diposting', 'kosong' => 'Belum dicatat'];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Simpanan Anggota</h1>
                <p class="text-xs text-stone-500">Setoran simpanan bulanan guru/karyawan dari potong gaji. Catat nominal, lalu posting agar saldo bertambah.</p>
            </div>
            <a href="{{ route('simpanan.setoran.rekap', ['jenis' => $typeId]) }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Rekap status</a>
        </div>
        <x-simpanan.nav active="simpanan.setoran" />
    </header>

    <div class="space-y-4 px-4 py-5 pb-28 sm:px-6">
        @if (! $this->type)
            <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">Belum ada jenis simpanan bulanan yang aktif. Atur di <a href="{{ route('simpanan') }}" wire:navigate class="font-semibold underline">Jenis simpanan</a>.</p>
        @else
            <div class="flex flex-col gap-2 sm:flex-row">
                <select wire:model.live="period" aria-label="Bulan" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                    @foreach ($this->months() as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                </select>
                <select wire:model.live="typeId" aria-label="Jenis simpanan" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                    @foreach ($this->types as $t)<option value="{{ $t->id }}">{{ $t->name }} · {{ $rp((int) $t->amount) }}/bulan</option>@endforeach
                </select>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama atau kode guru…" aria-label="Cari anggota"
                       class="flex-1 rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Guru/karyawan aktif</p><p class="text-2xl font-bold tabular-nums">{{ $s['members'] }}</p></div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Sudah disetor</p><p class="text-lg font-semibold text-emerald-800 tabular-nums">{{ $s['posted'] }} · {{ $rp($s['posted_amount']) }}</p></div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3"><p class="text-xs text-stone-500">Menunggu posting</p><p class="text-lg font-semibold text-amber-800 tabular-nums">{{ $s['pending'] }} · {{ $rp($s['pending_amount']) }}</p></div>
            </div>

            @error('amounts') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p> @enderror

            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Setoran bulanan guru">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead class="bg-stone-50 text-left text-xs text-stone-500">
                            <tr><th class="w-10 px-4 py-2"><span class="sr-only">Pilih</span></th><th class="px-3 py-2 font-medium">Anggota</th>
                                <th class="px-3 py-2 font-medium">Nominal potong gaji</th><th class="px-3 py-2 font-medium">Status</th><th class="px-4 py-2"><span class="sr-only">Aksi</span></th></tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($this->rows as $r)
                                @php $done = $r['status'] === 'disetor'; @endphp
                                <tr wire:key="due-{{ $r['member_id'] }}" @class(['bg-emerald-50/40' => in_array($r['member_id'], $selected, true)])>
                                    <td class="px-4 py-2">@unless ($done)<input type="checkbox" wire:model.live="selected" value="{{ $r['member_id'] }}" aria-label="Pilih {{ $r['name'] }}" class="accent-emerald-700">@endunless</td>
                                    <td class="px-3 py-2"><span class="font-mono text-xs text-stone-500">{{ $r['code'] }}</span> {{ $r['name'] }}</td>
                                    <td class="px-3 py-2">
                                        @if ($done)
                                            <span class="tabular-nums">{{ $rp($r['amount']) }}</span>
                                        @else
                                            <input type="number" min="1000" step="1000" wire:model="amounts.{{ $r['member_id'] }}" aria-label="Nominal {{ $r['name'] }}" @disabled(! $this->canManage)
                                                   class="w-32 rounded-lg border border-stone-300 px-2 py-1 text-sm tabular-nums">
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $statusTone[$r['status']] }}">{{ $statusLabel[$r['status']] }}</span>
                                        @if ($r['paid_elsewhere'])<span class="block text-xs text-stone-500">lewat setoran biasa</span>@endif
                                        @if ($r['posted_at'])<span class="block text-xs text-stone-500">{{ \Illuminate\Support\Carbon::parse($r['posted_at'])->translatedFormat('d M Y H:i') }}</span>@endif
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        @if ($r['status'] === 'belum' && $this->canManage)
                                            <button type="button" wire:click="discard({{ $r['due_id'] }})" class="text-xs font-medium text-red-700 hover:underline">Hapus catatan</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-10 text-center text-stone-400">Tidak ada anggota guru/karyawan aktif.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($this->recap)
                <section class="rounded-xl border border-stone-200 bg-white" aria-labelledby="recap-title">
                    <h2 id="recap-title" class="px-4 pt-4 font-semibold">Rekap per bulan · {{ $this->type->name }}</h2>
                    <table class="mt-2 w-full text-sm">
                        <thead class="text-left text-xs text-stone-500"><tr><th class="px-4 py-2 font-medium">Bulan</th><th class="px-3 py-2 text-right font-medium">Dicatat</th><th class="px-4 py-2 text-right font-medium">Sudah disetor</th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($this->recap as $m)
                                <tr wire:key="rc-{{ $m['period'] }}"><td class="px-4 py-2">{{ $m['label'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $m['recorded'] }} · {{ $rp($m['recorded_amount']) }}</td>
                                    <td class="px-4 py-2 text-right font-semibold text-emerald-800 tabular-nums">{{ $m['posted'] }} · {{ $rp($m['posted_amount']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif
        @endif
    </div>

    @if ($this->canManage && $this->type)
        <div class="sticky bottom-0 z-10 border-t border-stone-200 bg-white/95 px-4 py-3 backdrop-blur sm:px-6" role="region" aria-label="Aksi setoran">
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-sm"><strong class="tabular-nums">{{ count($selected) }}</strong> dipilih
                    <button type="button" wire:click="selectOpen" class="ml-2 text-xs font-medium text-emerald-800 hover:underline">Pilih semua yang belum disetor</button></p>
                <div class="ml-auto flex gap-2">
                    <button type="button" wire:click="save" class="rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Simpan catatan</button>
                    <button type="button" wire:click="postSelected" wire:confirm="Posting setoran terpilih? Saldo simpanan anggota akan bertambah." wire:loading.attr="disabled"
                            class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Posting ke saldo</button>
                </div>
            </div>
        </div>
    @endif
</div>
