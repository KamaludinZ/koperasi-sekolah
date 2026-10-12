<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Akun Saya</h1>
        <p class="text-xs text-stone-500">Perangkat yang sedang masuk dengan akun Anda.</p>
        @include('livewire.akun.partials.tabs')
    </header>

    <div class="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,32rem)_minmax(0,20rem)]">
        <div class="space-y-4">
            @if ($flash)
                <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">{{ $flash }}</p>
            @endif

            <ul class="divide-y divide-stone-100 overflow-hidden rounded-2xl border border-stone-200 bg-white">
                @forelse ($this->sessions as $s)
                    <li wire:key="session-{{ $s['id'] }}" class="flex items-center gap-3 px-4 py-3">
                        <span @class(['grid size-10 shrink-0 place-items-center rounded-xl', 'bg-emerald-950 text-amber-300' => $s['current'], 'bg-stone-100 text-stone-500' => ! $s['current']])>
                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true">
                                @if (in_array($s['device'], ['Android', 'iPhone/iPad'], true))<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>@else<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>@endif
                            </svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">{{ $s['browser'] }} di {{ $s['device'] }}
                                @if ($s['current'])<span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-800">Perangkat ini</span>@endif
                            </p>
                            <p class="text-xs text-stone-500">{{ $s['ip_address'] ?? 'IP tidak diketahui' }} · aktif {{ \Illuminate\Support\Carbon::parse($s['last_active'])->diffForHumans() }}</p>
                        </div>
                        @unless ($s['current'])
                            <button type="button" wire:click="revoke(@js($s['id']))" wire:confirm="Akhiri sesi di perangkat ini?"
                                    class="shrink-0 rounded-lg px-2.5 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">Akhiri</button>
                        @endunless
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-stone-500">Tidak ada sesi yang tercatat.</li>
                @endforelse
            </ul>

            <form wire:submit="revokeOthers" class="space-y-3 rounded-2xl border border-stone-200 bg-white p-5">
                <h2 class="font-semibold">Keluar dari semua perangkat lain</h2>
                <p class="text-sm text-stone-500">Gunakan bila Anda lupa keluar di komputer lain atau curiga akun dipakai orang lain.</p>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-stone-700">Kata sandi Anda</span>
                    <input type="password" wire:model="password" autocomplete="current-password"
                           class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                    @error('password') <span class="mt-1 block text-sm text-red-600">{{ $message }}</span> @enderror
                </label>
                <button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900 disabled:opacity-60">Keluarkan perangkat lain</button>
            </form>
        </div>

        <aside class="relative isolate h-fit overflow-hidden rounded-2xl bg-emerald-950 p-5 text-emerald-50">
            <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.08]" aria-hidden="true"></div>
            <h2 class="font-display text-xl font-bold text-white">Menjaga amanah akun</h2>
            <ul class="mt-3 space-y-2.5 text-sm text-emerald-100">
                @if ($idle > 0)<li class="flex gap-2.5"><span class="text-amber-300" aria-hidden="true">✦</span>Sesi otomatis berakhir setelah {{ $idle }} menit tanpa aktivitas.</li>@endif
                <li class="flex gap-2.5"><span class="text-amber-300" aria-hidden="true">✦</span>Akun yang dinonaktifkan admin langsung keluar dari semua perangkat.</li>
                <li class="flex gap-2.5"><span class="text-amber-300" aria-hidden="true">✦</span>Semua masuk, keluar, dan pengakhiran sesi tercatat di Audit & Riwayat.</li>
            </ul>
        </aside>
    </div>
</div>
