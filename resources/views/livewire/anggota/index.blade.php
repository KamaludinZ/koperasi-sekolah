@php
    $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
    $initials = fn ($name) => collect(explode(' ', preg_replace('/^(Pak|Bu)\s+/', '', $name)))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->join('');
    $select = 'rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600';
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Anggota & Tabungan</h1>
            <p class="text-xs text-stone-500">Data siswa dan guru anggota koperasi beserta saldo tabungannya.</p>
        </div>
        <button type="button" wire:click="create"
                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
            Tambah anggota
        </button>
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        @if ($flash)
            <p class="rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900" role="status">{{ $flash }}</p>
        @endif

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Anggota aktif</p>
                <p class="text-2xl font-bold tabular-nums">{{ $this->stats['total'] }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Siswa</p>
                <p class="text-2xl font-bold tabular-nums">{{ $this->stats['siswa'] }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                <p class="text-xs text-stone-500">Guru</p>
                <p class="text-2xl font-bold tabular-nums">{{ $this->stats['guru'] }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                <p class="text-xs text-emerald-800">Total saldo tabungan</p>
                <p class="text-xl font-bold text-emerald-900 tabular-nums sm:text-2xl">{{ $rp($this->stats['balance']) }}</p>
            </div>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <label class="relative flex-1 sm:min-w-56">
                <span class="sr-only">Cari anggota</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.200ms="search" placeholder="Cari nama atau kode anggota…"
                       class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
            <select wire:model.live="type" aria-label="Jenis anggota" class="{{ $select }}">
                <option value="">Siswa & guru</option>
                @foreach (\App\Livewire\Anggota\Index::TYPES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="class" aria-label="Kelas" @disabled($type === 'guru') class="{{ $select }} disabled:opacity-50">
                <option value="">Semua kelas</option>
                @foreach ($this->classes as $c)
                    <option value="{{ $c }}">Kelas {{ $c }}</option>
                @endforeach
            </select>
            <label class="inline-flex items-center gap-2 px-1 text-sm text-stone-600">
                <input type="checkbox" wire:model.live="showInactive" class="size-4 accent-emerald-700"> Tampilkan nonaktif
            </label>
        </div>

        @if (count($this->members) === 0)
            <div class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center">
                <p class="text-sm font-medium text-stone-600">Tidak ada anggota yang cocok</p>
                <button type="button" wire:click="resetFilters" class="mt-2 text-sm font-medium text-emerald-700 hover:underline">Hapus filter</button>
            </div>
        @else
            <ul class="divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 bg-white">
                @foreach ($this->members as $m)
                    <li wire:key="member-{{ $m['id'] }}" @class(['flex items-center gap-3 px-4 py-3', 'bg-stone-50 text-stone-400' => ! $m['is_active']])>
                        <span @class(['grid size-10 shrink-0 place-items-center rounded-full text-sm font-semibold',
                                      'bg-emerald-100 text-emerald-800' => $m['type'] === 'siswa',
                                      'bg-amber-100 text-amber-900' => $m['type'] === 'guru'])>{{ $initials($m['name']) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">
                                <a href="{{ route('anggota.show', $m['id']) }}" wire:navigate class="hover:text-emerald-800 hover:underline">{{ $m['name'] }}</a>
                                @unless ($m['is_active'])<span class="ml-1 rounded bg-stone-200 px-1.5 py-0.5 text-[11px] font-medium text-stone-600">Nonaktif</span>@endunless
                            </p>
                            <p class="truncate text-xs text-stone-500">
                                <span class="font-mono">{{ $m['member_code'] }}</span>
                                · {{ \App\Livewire\Anggota\Index::TYPES[$m['type']] }}{{ $m['class'] ? ' kelas '.$m['class'] : '' }}
                                @if ($m['phone']) <span class="hidden sm:inline">· {{ $m['phone'] }}</span> @endif
                            </p>
                        </div>
                        <button type="button" wire:click="edit({{ $m['id'] }})" aria-label="Ubah data {{ $m['name'] }}"
                                class="rounded-lg px-2 py-1 text-sm font-medium text-emerald-700 hover:bg-emerald-50">Ubah</button>
                        <div class="w-24 text-right">
                            <p class="text-[11px] text-stone-500">Saldo</p>
                            <p @class(['font-semibold tabular-nums', 'text-stone-400' => $m['balance'] === 0, 'text-emerald-800' => $m['balance'] > 0])>{{ $rp($m['balance']) }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
            <p class="text-xs text-stone-500">Menampilkan {{ count($this->members) }} anggota.</p>
        @endif
    </div>

    @include('livewire.anggota.partials.member-form')
</div>
