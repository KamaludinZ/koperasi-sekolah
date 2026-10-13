@php
    $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
    [$from, $to] = $this->range;
    $shifts = $this->shifts;
    $filtered = $cashier !== '' || $status !== '';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Riwayat & Selisih Shift</h1>
                <p class="text-xs text-stone-500">{{ $from->translatedFormat('d M Y') }} – {{ $to->translatedFormat('d M Y') }}</p>
            </div>
            <div class="flex gap-1 rounded-xl bg-stone-100 p-1" role="group" aria-label="Periode">
                @foreach (\App\Livewire\Kas\Shifts::PERIODS as $key => $label)
                    <button type="button" wire:click="setPeriod('{{ $key }}')" aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                            @class(['rounded-lg px-3 py-1.5 text-sm font-medium transition',
                                    'bg-white text-stone-900 shadow-sm' => $period === $key,
                                    'text-stone-600 hover:text-stone-900' => $period !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <x-kas.nav active="kas.shift" />
    </header>

    <div class="space-y-5 px-4 py-5 sm:px-6" wire:loading.class="opacity-60" wire:target="setPeriod,setStatus,cashier,resetFilters">
        <p class="rounded-xl border border-dashed border-amber-300 bg-amber-50/60 px-4 py-2 text-xs text-amber-900">Tahap pengembangan: angka di halaman ini masih data tiruan, belum dari transaksi sungguhan.</p>

        {{-- Selisih per petugas --}}
        <section aria-labelledby="per-cashier">
            <h2 id="per-cashier" class="mb-2 text-sm font-semibold text-stone-700">Selisih per petugas</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($this->perCashier as $p)
                    <button type="button" wire:click="$set('cashier', @js($cashier === $p['name'] ? '' : $p['name']))" aria-pressed="{{ $cashier === $p['name'] ? 'true' : 'false' }}"
                            @class(['rounded-xl border bg-white px-4 py-3 text-left transition hover:border-emerald-400',
                                    'border-emerald-600 ring-2 ring-emerald-600/20' => $cashier === $p['name'],
                                    'border-stone-200' => $cashier !== $p['name']])>
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="font-semibold">{{ $p['name'] }}</p>
                            <x-kas.difference-badge :amount="$p['difference']" size="md" />
                        </div>
                        <p class="mt-0.5 text-xs text-stone-500">{{ $p['shifts'] }} shift · {{ $p['transactions'] }} transaksi</p>
                        <p class="mt-2 flex flex-wrap gap-x-3 text-xs">
                            <span class="text-emerald-800">{{ $p['exact'] }} pas</span>
                            <span class="text-red-700">{{ $p['short'] }} kurang</span>
                            <span class="text-amber-800">{{ $p['over'] }} lebih</span>
                        </p>
                    </button>
                @empty
                    <p class="text-sm text-stone-400">Belum ada shift pada periode ini.</p>
                @endforelse
            </div>
        </section>

        {{-- Saringan --}}
        <div class="flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 text-sm">
                <span class="text-stone-600">Petugas</span>
                <select wire:model.live="cashier" class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm outline-none focus:border-emerald-600">
                    <option value="">Semua petugas</option>
                    @foreach ($this->cashiers as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex flex-wrap gap-1" role="group" aria-label="Status selisih">
                @foreach (\App\Livewire\Kas\Shifts::STATUSES as $key => $label)
                    <button type="button" wire:click="setStatus('{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}"
                            @class(['rounded-full border px-3 py-1 text-sm font-medium',
                                    'border-emerald-700 bg-emerald-800 text-white' => $status === $key,
                                    'border-stone-300 bg-white text-stone-600 hover:border-stone-400' => $status !== $key])>{{ $label }}</button>
                @endforeach
            </div>
            @if ($filtered)
                <button type="button" wire:click="resetFilters" class="text-sm font-semibold text-emerald-800 hover:underline">Hapus saringan</button>
            @endif
            <p class="ml-auto text-xs text-stone-500">{{ count($shifts) }} dari {{ count($this->all) }} shift</p>
        </div>

        {{-- Daftar shift --}}
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar shift">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[46rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Shift</th>
                            <th class="px-3 py-2 font-medium">Petugas</th>
                            <th class="px-3 py-2 text-right font-medium">Transaksi</th>
                            <th class="px-3 py-2 text-right font-medium">Seharusnya</th>
                            <th class="px-3 py-2 text-right font-medium">Dihitung</th>
                            <th class="px-3 py-2 text-right font-medium">Selisih</th>
                            <th class="px-4 py-2 font-medium">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($shifts as $s)
                            @php
                                $opened = \Illuminate\Support\Carbon::parse($s['opened_at']);
                            @endphp
                            <tr wire:key="sh-{{ $s['key'] }}" class="align-top">
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <a href="{{ route('kas', ['tanggal' => $opened->isToday() ? null : $s['date']]) }}" wire:navigate class="font-medium text-emerald-800 hover:underline">{{ $opened->translatedFormat('D, d M') }}</a>
                                    <p class="text-xs text-stone-500 tabular-nums">{{ $s['name'] }} · {{ $opened->format('H:i') }}–{{ $s['closed_at'] ? \Illuminate\Support\Carbon::parse($s['closed_at'])->format('H:i') : 'sekarang' }}</p>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ $s['cashier'] }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $s['transactions'] }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $rp($s['expected']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $s['counted'] === null ? '—' : $rp($s['counted']) }}</td>
                                <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                    <x-kas.difference-badge :amount="$s['difference']" :running="$s['status'] === 'open'" />
                                </td>
                                <td class="max-w-56 px-4 py-2.5 text-xs text-stone-600">
                                    {{ $s['note'] ?? '—' }}
                                    @if ($s['movements'])<span class="block text-stone-400">{{ $s['movements'] }} catatan setor/tarik kas</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">Tidak ada shift yang cocok dengan saringan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
