<a href="{{ route('notifikasi') }}" wire:navigate
   @class(['relative grid place-items-center rounded-lg text-emerald-200 hover:bg-emerald-800 hover:text-white', 'size-9' => ! $compact, 'size-10' => $compact,
           'bg-emerald-800 text-white' => request()->routeIs('notifikasi')])
   aria-label="Notifikasi{{ $unread ? ", {$unread} belum dibaca" : '' }}" title="Notifikasi">
    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/></svg>
    @if ($unread)
        <span class="absolute -top-0.5 -right-0.5 grid min-w-4.5 place-items-center rounded-full bg-amber-400 px-1 text-[10px] leading-4.5 font-bold text-emerald-950 tabular-nums">{{ $unread > 9 ? '9+' : $unread }}</span>
    @endif
</a>
