{{-- Riwayat mutasi tabungan dengan filter rentang tanggal & jenis --}}
@php
    $h = $this->history;
    $dateInput = 'rounded-lg border bg-white px-2.5 py-1.5 text-sm outline-none focus:border-emerald-600';
@endphp
<section class="rounded-xl border border-stone-200 bg-white" aria-labelledby="history-title">
    <div class="space-y-3 border-b border-stone-200 px-4 py-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="history-title" class="text-sm font-semibold">Riwayat tabungan</h2>
            <div class="flex gap-1" role="group" aria-label="Jenis mutasi">
                @foreach (['' => 'Semua', 'setor' => 'Setor', 'tarik' => 'Tarik'] as $value => $label)
                    <button type="button" wire:click="$set('historyType', '{{ $value }}')" aria-pressed="{{ $historyType === $value ? 'true' : 'false' }}"
                            @class(['rounded-md px-2.5 py-1 text-xs font-medium',
                                    'bg-stone-800 text-white' => $historyType === $value,
                                    'text-stone-600 hover:bg-stone-100' => $historyType !== $value])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <div class="flex gap-1 overflow-x-auto" role="group" aria-label="Periode">
                @foreach (\App\Livewire\Anggota\Show::HISTORY_PRESETS as $key => $label)
                    <button type="button" wire:click="applyHistoryPreset('{{ $key }}')" aria-pressed="{{ $historyPreset === $key ? 'true' : 'false' }}"
                            @class(['shrink-0 rounded-full px-3 py-1 text-xs font-medium',
                                    'bg-emerald-900 text-white' => $historyPreset === $key,
                                    'border border-stone-300 text-stone-700 hover:border-stone-400' => $historyPreset !== $key])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="ml-auto flex items-end gap-2">
                <label class="text-[11px] text-stone-500"><span class="block">Dari</span>
                    <input type="date" wire:model.live="historyFrom" max="{{ now()->toDateString() }}"
                           class="{{ $dateInput }} {{ $historyPreset === 'kustom' ? 'border-emerald-600' : 'border-stone-300' }}">
                </label>
                <label class="text-[11px] text-stone-500"><span class="block">Sampai</span>
                    <input type="date" wire:model.live="historyTo" max="{{ now()->toDateString() }}"
                           class="{{ $dateInput }} {{ $historyPreset === 'kustom' ? 'border-emerald-600' : 'border-stone-300' }}">
                </label>
            </div>
        </div>
        @error('historyTo') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
        @if ($h['filtered'] && count($h['rows']) > 0)
            <p class="text-xs text-stone-500">
                Periode ini: masuk <strong class="text-emerald-800 tabular-nums">+{{ $rp($h['in']) }}</strong>,
                keluar <strong class="text-red-700 tabular-nums">−{{ $rp($h['out']) }}</strong>
                · {{ count($h['rows']) }} mutasi
            </p>
        @endif
    </div>

    @if (count($h['rows']) === 0)
        <div class="px-6 py-10 text-center">
            @if ($this->stats['deposit_count'] + $this->stats['withdraw_count'] === 0)
                <p class="text-sm font-medium text-stone-600">Belum ada mutasi tabungan</p>
                <p class="text-xs text-stone-400">Setoran pertama akan tampil di sini.</p>
            @else
                <p class="text-sm font-medium text-stone-600">Tidak ada mutasi pada filter ini</p>
                <button type="button" wire:click="applyHistoryPreset('semua')" x-on:click="$wire.set('historyType', '')" class="mt-1 text-sm font-medium text-emerald-700 hover:underline">Tampilkan semua</button>
            @endif
        </div>
    @else
        <ul class="divide-y divide-stone-100">
            @foreach ($h['rows'] as $row)
                @php $in = $row['type'] === 'setor'; @endphp
                <li wire:key="saving-{{ $row['id'] }}" class="flex items-center gap-3 px-4 py-3">
                    <span @class(['grid size-8 shrink-0 place-items-center rounded-full text-sm font-bold',
                                  'bg-emerald-100 text-emerald-700' => $in, 'bg-red-100 text-red-700' => ! $in])
                          aria-hidden="true">{{ $in ? '+' : '−' }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ $row['note'] }}</p>
                        <p class="text-xs text-stone-500">{{ \Illuminate\Support\Carbon::parse($row['created_at'])->translatedFormat('d M Y · H:i') }} · {{ $row['user'] }}</p>
                    </div>
                    <div class="text-right">
                        <p @class(['text-sm font-semibold tabular-nums', 'text-emerald-800' => $in, 'text-red-700' => ! $in])>
                            <span class="sr-only">{{ $in ? 'Setor' : 'Tarik' }}</span>{{ $in ? '+' : '−' }}{{ $rp($row['amount']) }}
                        </p>
                        <p class="text-[11px] text-stone-500 tabular-nums">Saldo {{ $rp($row['balance_after']) }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
