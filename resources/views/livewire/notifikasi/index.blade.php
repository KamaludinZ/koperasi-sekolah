@php
    $types = \App\Livewire\Notifikasi\Index::TYPES;
    $targets = \App\Livewire\Notifikasi\Index::TARGETS;
    $style = [
        'stok' => ['bg-amber-100 text-amber-900', 'M20 7l-8-4-8 4m16 0v10l-8 4m8-14l-8 4m0 10l-8-4V7m8 14V11'],
        'pengumuman' => ['bg-emerald-100 text-emerald-800', 'M3 11l18-8v18L3 13zM7 13v5a2 2 0 004 0v-3'],
        'sistem' => ['bg-sky-100 text-sky-800', 'M12 8h.01M11 12h1v4h1M12 21a9 9 0 110-18 9 9 0 010 18z'],
    ];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Notifikasi</h1>
            <p class="text-xs text-stone-500">Peringatan stok, pengumuman, dan kabar sistem. <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">data tiruan</span></p>
        </div>
        @if ($this->counts['belum'] > 0)
            <button type="button" wire:click="markAllRead" class="rounded-xl border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-800 hover:bg-stone-50">Tandai semua dibaca</button>
        @endif
    </header>

    <div class="mx-auto w-full max-w-3xl space-y-4 px-4 py-5 sm:px-6">
        <div class="flex gap-1 overflow-x-auto rounded-xl bg-stone-200/60 p-1" role="tablist" aria-label="Saring notifikasi">
            @foreach (\App\Livewire\Notifikasi\Index::FILTERS as $key => $label)
                <button type="button" role="tab" wire:click="setFilter('{{ $key }}')" aria-selected="{{ $filter === $key ? 'true' : 'false' }}"
                        @class(['flex shrink-0 items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-sm font-medium', 'bg-white text-emerald-900 shadow-sm' => $filter === $key, 'text-stone-600 hover:text-stone-900' => $filter !== $key])>
                    {{ $label }}
                    <span @class(['rounded-full px-1.5 text-[11px] tabular-nums', 'bg-amber-400 font-bold text-emerald-950' => $key === 'belum' && $this->counts['belum'] > 0, 'bg-stone-200 text-stone-600' => ! ($key === 'belum' && $this->counts['belum'] > 0)])>{{ $this->counts[$key] }}</span>
                </button>
            @endforeach
        </div>

        @forelse ($this->days as $date => $items)
            <section wire:key="nday-{{ $date }}" aria-labelledby="nday-{{ $date }}">
                <h2 id="nday-{{ $date }}" class="mb-1.5 text-sm font-semibold">{{ \App\Livewire\Notifikasi\Index::dayLabel($date) }}</h2>
                <ul class="divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 bg-white">
                    @foreach ($items as $n)
                        @php [$tone, $icon] = $style[$n['type']] ?? $style['sistem']; @endphp
                        <li wire:key="notif-{{ $n['id'] }}" @class(['flex items-start gap-3 px-4 py-3', 'bg-emerald-50/50' => ! $n['is_read']])>
                            <span class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-lg {{ $tone }}" title="{{ $types[$n['type']] }}">
                                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p @class(['text-sm', 'font-semibold text-stone-900' => ! $n['is_read'], 'font-medium text-stone-700' => $n['is_read']])>
                                    @unless ($n['is_read'])<span class="mr-1 inline-block size-2 rounded-full bg-amber-500 align-middle" aria-label="Belum dibaca"></span>@endunless
                                    {{ $n['title'] }}
                                </p>
                                <p class="mt-0.5 text-sm text-stone-600">{{ $n['message'] }}</p>
                                <p class="mt-1 text-xs text-stone-500">
                                    {{ \Illuminate\Support\Carbon::parse($n['created_at'])->format('H:i') }} · {{ $types[$n['type']] }} · untuk {{ $targets[$n['target']] ?? $n['target'] }}
                                </p>
                                <div class="mt-2 flex flex-wrap gap-3 text-sm">
                                    @if ($n['url'])
                                        <button type="button" wire:click="open({{ $n['id'] }})" class="font-semibold text-emerald-800 hover:underline">Lihat barang →</button>
                                    @endif
                                    <button type="button" wire:click="toggleRead({{ $n['id'] }})" class="text-stone-500 hover:text-stone-800">
                                        {{ $n['is_read'] ? 'Tandai belum dibaca' : 'Tandai dibaca' }}
                                    </button>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center">
                <p class="text-sm font-medium text-stone-600">{{ $filter === 'belum' ? 'Semua notifikasi sudah dibaca. Alhamdulillah!' : 'Belum ada notifikasi.' }}</p>
            </div>
        @endforelse
    </div>
</div>
