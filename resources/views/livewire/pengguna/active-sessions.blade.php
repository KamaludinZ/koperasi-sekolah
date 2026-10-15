@php $rows = $this->sessions; @endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto" wire:poll.60s>
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Kelola Pengguna</h1>
        <p class="text-xs text-stone-500">Petugas yang aktif dalam {{ $idle }} menit terakhir. Keluarkan sesi bila perangkat hilang atau ditinggal.</p>
        <x-pengguna.nav active="pengguna.sesi" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <p class="text-sm text-stone-600"><strong class="tabular-nums">{{ count($rows) }}</strong> sesi aktif dari <strong class="tabular-nums">{{ collect($rows)->pluck('user_id')->unique()->count() }}</strong> petugas.</p>
        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Sesi aktif">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500"><tr><th class="px-4 py-2 font-medium">Petugas</th><th class="px-3 py-2 font-medium">Perangkat</th><th class="px-3 py-2 font-medium">IP</th><th class="px-3 py-2 font-medium">Aktivitas terakhir</th><th class="px-4 py-2"></th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($rows as $s)
                            <tr wire:key="ses-{{ $s['id'] }}">
                                <td class="px-4 py-2.5"><span class="font-medium">{{ $s['name'] }}</span>@if ($s['current']) <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-800">sesi Anda</span>@endif<span class="block text-xs text-stone-500">{{ collect($s['roles'] ?? [$s['role']])->filter()->map(fn ($r) => \App\Models\Role::LABELS[$r] ?? ucfirst($r))->join(' · ') ?: '—' }} · {{ $s['email'] }}</span></td>
                                <td class="px-3 py-2.5 text-stone-700">{{ $s['device'] }} · {{ $s['browser'] }}</td>
                                <td class="px-3 py-2.5 font-mono text-xs text-stone-600">{{ $s['ip_address'] ?? '—' }}</td>
                                <td class="px-3 py-2.5 text-stone-600">{{ \Illuminate\Support\Carbon::parse($s['last_active'])->diffForHumans() }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    @unless ($s['current'])
                                        <button type="button" wire:click="confirmEnd('{{ $s['id'] }}')" class="text-sm font-medium text-red-700 hover:underline">Keluarkan</button>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-stone-400">Tidak ada sesi aktif.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        @if (\App\Support\Menu::canReach('audit'))
            <p class="text-xs text-stone-500">Riwayat masuk/keluar ada di <a href="{{ route('audit', ['tampil' => 'login']) }}" wire:navigate class="font-medium text-emerald-800 hover:underline">Audit → Login</a>.</p>
        @endif
    </div>

    @if ($ending && ($sel = collect($rows)->firstWhere('id', $ending)))
        <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="end-title" x-data x-on:keydown.escape.window="$wire.set('ending', null)">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="$set('ending', null)"></div>
            <form wire:submit="end" class="relative w-full max-w-md space-y-3 rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                <h2 id="end-title" class="font-semibold">Keluarkan sesi {{ $sel['name'] }}?</h2>
                <p class="text-sm text-stone-600">{{ $sel['device'] }} · {{ $sel['browser'] }} · {{ $sel['ip_address'] }}. Petugas harus masuk lagi di perangkat itu.</p>
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Alasan</span>
                    <input type="text" wire:model="reason" maxlength="150" placeholder="mis. HP kasir hilang" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    @error('reason') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="$set('ending', null)" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                    <button type="submit" class="rounded-xl bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Keluarkan</button>
                </div>
            </form>
        </div>
    @endif
</div>
