<div class="relative" wire:poll.60s x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
    <button type="button" x-on:click="open = !open" x-bind:aria-expanded="open" aria-haspopup="dialog"
            @class(['relative grid place-items-center rounded-lg text-emerald-200 hover:bg-emerald-800 hover:text-white', 'size-9' => ! $compact, 'size-10' => $compact])
            x-bind:class="open && 'bg-emerald-800 text-white'"
            aria-label="Notifikasi{{ $unread ? ", {$unread} belum dibaca" : '' }}" title="Notifikasi">
        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/></svg>
        @if ($unread)
            <span class="absolute -top-0.5 -right-0.5 grid min-w-4.5 place-items-center rounded-full bg-amber-400 px-1 text-[10px] leading-4.5 font-bold text-emerald-950 tabular-nums">{{ $unread > 9 ? '9+' : $unread }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.origin.top.left role="dialog" aria-label="Notifikasi terbaru"
         {{-- Sidebar memotong isi (overflow-hidden), jadi panel versi sidebar memakai posisi tetap di samping sidebar. --}}
         @class(['z-50 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl bg-white text-stone-900 shadow-xl ring-1 ring-stone-200',
                 'fixed top-16 left-4 lg:top-4 lg:left-[15.5rem]' => ! $compact, 'absolute right-0 mt-2' => $compact])>
        <div class="flex items-center justify-between border-b border-stone-100 px-4 py-2.5">
            <p class="text-sm font-semibold">Notifikasi @if ($unread)<span class="font-normal text-stone-500">· {{ $unread }} baru</span>@endif</p>
            @if ($unread)
                <button type="button" wire:click="markAllRead" class="text-xs font-medium text-emerald-800 hover:underline">Tandai semua dibaca</button>
            @endif
        </div>
        <ul class="max-h-96 divide-y divide-stone-100 overflow-y-auto">
            @forelse ($latest as $n)
                <li wire:key="bell-{{ $n['id'] }}" @class(['flex items-start gap-2 px-4 py-2.5', 'bg-emerald-50/60' => ! $n['is_read']])>
                    <button type="button" wire:click="open({{ $n['id'] }})" class="min-w-0 flex-1 text-left">
                        <span @class(['block truncate text-sm', 'font-semibold' => ! $n['is_read']])>{{ $n['title'] }}</span>
                        <span class="block truncate text-xs text-stone-500">{{ $n['message'] }}</span>
                        <span class="block text-[11px] text-stone-400">{{ \Illuminate\Support\Carbon::parse($n['created_at'])->diffForHumans() }}</span>
                    </button>
                    @unless ($n['is_read'])
                        <button type="button" wire:click="markRead({{ $n['id'] }})" class="mt-1 grid size-6 shrink-0 place-items-center rounded-full text-stone-400 hover:bg-stone-100 hover:text-emerald-700" aria-label="Tandai dibaca: {{ $n['title'] }}" title="Tandai dibaca">
                            <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 13 4 4L19 7"/></svg>
                        </button>
                    @endunless
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-stone-500">Belum ada notifikasi.</li>
            @endforelse
        </ul>
        <a href="{{ route('notifikasi') }}" wire:navigate class="block border-t border-stone-100 px-4 py-2.5 text-center text-sm font-semibold text-emerald-800 hover:bg-stone-50">Lihat semua notifikasi</a>
    </div>
</div>
