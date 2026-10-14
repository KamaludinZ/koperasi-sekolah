@php $rp = fn ($n) => \App\Support\Rupiah::format($n); $apps = $this->applications; $counts = $this->counts; @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Pengajuan Pinjaman</h1>
                <p class="text-xs text-stone-500">Pengajuan pinjaman anggota yang dicatat pengurus: menunggu keputusan, disetujui, ditolak, atau dibatalkan.</p>
            </div>
            @can('kelola-pinjaman')
                <button type="button" wire:click="create"
                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                    Catat pengajuan
                </button>
            @endcan
        </div>
        <x-pinjaman.nav active="pinjaman.pengajuan" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        @if ($flash)
            <p class="rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900" role="status">{{ $flash }}</p>
        @endif
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-100 p-1" role="tablist" aria-label="Tahap pengajuan">
                @foreach (\App\Livewire\Pinjaman\Applications::TABS as $key => $label)
                    <button type="button" role="tab" wire:click="setTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" data-tab="{{ $key }}"
                            @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-white text-stone-900 shadow-sm' => $tab === $key, 'text-stone-600 hover:text-stone-900' => $tab !== $key])>
                        {{ $label }} <span class="ml-1 rounded-full bg-stone-200 px-1.5 text-xs tabular-nums">{{ $counts[$key] }}</span>
                    </button>
                @endforeach
            </div>
            <label class="relative sm:w-72">
                <span class="sr-only">Cari pengajuan</span>
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" wire:model.live.debounce.250ms="search" maxlength="100" placeholder="Cari kode, nama, atau kode anggota…"
                       class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
            </label>
        </div>

        @if ($apps->total() === 0)
            <div class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center" data-empty="pengajuan">
                <p class="text-sm font-medium text-stone-700">
                    @if ($search !== '') Tidak ada pengajuan yang cocok @else Tidak ada pengajuan {{ mb_strtolower(\App\Livewire\Pinjaman\Applications::TABS[$tab] ?? '') }} @endif
                </p>
                <p class="mt-1 text-sm text-stone-500">{{ $tab === 'diajukan' ? 'Pengajuan baru yang dicatat pengurus akan menunggu keputusan di sini.' : 'Pengajuan akan pindah ke tab ini setelah diputuskan.' }}</p>
            </div>
        @else
            <ul class="grid gap-3 lg:grid-cols-2" aria-label="Daftar pengajuan">
                @foreach ($apps as $a)
                    <li wire:key="app-{{ $a['id'] }}" class="rounded-xl border border-stone-200 bg-white p-4" data-application="{{ $a['code'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-semibold">{{ $a['member']['name'] ?? '—' }}</p>
                                <p class="text-xs text-stone-500"><span class="font-mono">{{ $a['member']['code'] ?? '' }}</span> · <a href="{{ route('pinjaman.show', $a['id']) }}" wire:navigate class="font-mono text-emerald-800 hover:underline">{{ $a['code'] }}</a></p>
                            </div>
                            <x-pinjaman.status :status="$a['status']" />
                        </div>
                        <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
                            <div><dt class="text-xs text-stone-500">Nominal</dt><dd class="font-semibold tabular-nums">{{ $rp($a['principal']) }}</dd></div>
                            <div><dt class="text-xs text-stone-500">Bunga</dt><dd class="tabular-nums">{{ $rp($a['interest']) }}</dd></div>
                            <div><dt class="text-xs text-stone-500">Tenor</dt><dd>{{ $a['tenor_months'] }} bln · <span class="tabular-nums">{{ $rp($a['monthly']) }}</span></dd></div>
                        </dl>
                        @if ($a['purpose'])<p class="mt-2 text-sm text-stone-700">{{ $a['purpose'] }}</p>@endif
                        @if ($a['decision_note'])<p class="mt-2 rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600">Catatan: {{ $a['decision_note'] }}</p>@endif
                        @if ($a['cancel_reason'])<p class="mt-2 rounded-lg bg-stone-100 px-3 py-2 text-xs text-stone-600">Alasan batal: {{ $a['cancel_reason'] }}</p>@endif
                        <p class="mt-2 text-xs text-stone-500">{{ $a['payment_label'] }} · Diajukan @tanggal($a['applied_at'])</p>
                        @if (in_array($a['status'], \App\Services\LoanApplicationService::EDITABLE, true))
                            @can('kelola-pinjaman')
                                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-stone-100 pt-3">
                                    <button type="button" wire:click="edit({{ $a['id'] }})" class="text-sm font-medium text-emerald-800 hover:underline">Ubah</button>
                                    <button type="button" wire:click="openCancel({{ $a['id'] }})" class="text-sm font-medium text-red-700 hover:underline">Batalkan</button>
                                    @if ($a['status'] === 'diajukan')
                                        <button type="button" wire:click="openDecision({{ $a['id'] }}, 'ditolak')" class="ml-auto rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Tolak</button>
                                        <button type="button" wire:click="openDecision({{ $a['id'] }}, 'disetujui')" class="rounded-lg bg-emerald-700 px-4 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800">Setujui</button>
                                    @endif
                                </div>
                            @endcan
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($apps->hasPages())
                <div class="flex items-center justify-between text-sm">
                    <button type="button" wire:click="previousPage" @disabled($apps->onFirstPage()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Sebelumnya</button>
                    <span class="text-stone-500">Halaman {{ $apps->currentPage() }} dari {{ $apps->lastPage() }}</span>
                    <button type="button" wire:click="nextPage" @disabled(! $apps->hasMorePages()) class="rounded-lg border border-stone-300 px-3 py-1.5 disabled:opacity-40">Berikutnya</button>
                </div>
            @endif
        @endif
    </div>

    @include('livewire.pinjaman.partials.application-form')
    @include('livewire.pinjaman.partials.decision-dialog')
    @include('livewire.pinjaman.partials.cancel-dialog')
</div>
