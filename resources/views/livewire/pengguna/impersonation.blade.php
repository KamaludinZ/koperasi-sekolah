@php
    $labels = \App\Models\Role::LABELS;
    $roleChips = fn (array $names) => collect($names)->map(fn ($r) => $labels[$r] ?? $r)->join(' · ');
    $sel = $this->targetModel;
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Kelola Pengguna</h1>
        <p class="text-xs text-stone-500">Masuk sementara sebagai petugas atau anggota portal untuk menelusuri masalah yang mereka alami. Semua aksi tercatat atas nama Anda.</p>
        <x-pengguna.nav active="pengguna.impersonasi" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="note">
            <p class="font-semibold">Sebelum masuk sebagai akun lain</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs">
                <li>Sesi dibatasi {{ $maxMinutes }} menit, lalu otomatis kembali ke akun Anda. Spanduk penanda selalu tampil selama sesi.</li>
                <li>Aksi selama impersonasi dicatat di jejak audit atas nama Anda, lengkap dengan alasan yang Anda tulis.</li>
                <li>Akun Admin lain, akun nonaktif, dan anggota tanpa akun portal tidak bisa dimasuki.</li>
            </ul>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <div class="flex gap-1 rounded-lg bg-stone-200/60 p-0.5 text-sm" role="tablist" aria-label="Jenis akun">
                @foreach (['petugas' => 'Petugas', 'anggota' => 'Anggota portal'] as $key => $label)
                    <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                            @class(['rounded-md px-3 py-1.5 font-medium', 'bg-white text-stone-900 shadow-sm' => $tab === $key, 'text-stone-600 hover:text-stone-900' => $tab !== $key])>
                        {{ $label }} <span class="ml-1 text-xs text-stone-500 tabular-nums" data-eligible-count="{{ $key }}">{{ $this->stats[$key] }}</span>
                    </button>
                @endforeach
            </div>
            <label class="relative min-w-56 flex-1">
                <span class="sr-only">Cari akun</span>
                <input type="search" wire:model.live.debounce.300ms="search" maxlength="60"
                       placeholder="{{ $tab === 'anggota' ? 'Cari nama atau kode anggota…' : 'Cari nama atau email petugas…' }}"
                       class="w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-sm">
            </label>
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="Daftar akun">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm">
                    <thead class="bg-stone-50 text-left text-xs text-stone-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ $tab === 'anggota' ? 'Anggota' : 'Petugas' }}</th>
                            <th class="px-3 py-2 font-medium">{{ $tab === 'anggota' ? 'Jenis' : 'Peran' }}</th>
                            <th class="px-3 py-2 font-medium">Terakhir masuk</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($rows as $row)
                            @php
                                $denial = $this->denial($row);
                                $type = $tab === 'anggota' ? 'anggota' : 'petugas';
                            @endphp
                            <tr wire:key="{{ $type }}-{{ $row->id }}" data-account="{{ $type }}-{{ $row->id }}" @class(['text-stone-400' => $denial])>
                                <td class="px-4 py-2.5">
                                    <span class="font-medium">{{ $row->name }}</span>
                                    <span class="block text-xs text-stone-500">{{ $tab === 'anggota' ? $row->member_code.($row->class ? ' · '.$row->class : '') : $row->email }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-stone-600">
                                    {{ $tab === 'anggota' ? (\App\Models\Member::TYPES[$row->type] ?? $row->type) : ($roleChips($row->roleNames()) ?: '—') }}
                                </td>
                                <td class="px-3 py-2.5 text-stone-600">
                                    @php $last = $tab === 'anggota' ? $row->portal_last_login_at : $row->last_login_at; @endphp
                                    {{ $last ? $last->diffForHumans() : 'Belum pernah' }}
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    @if ($denial)
                                        <span class="text-xs" data-denied>{{ $denial }}</span>
                                    @else
                                        <button type="button" wire:click="confirm('{{ $type }}', {{ $row->id }})"
                                                class="rounded-lg border border-emerald-700 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-50">Masuk sebagai</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-10 text-center text-stone-400">{{ $search !== '' ? 'Tidak ada akun yang cocok.' : ($tab === 'anggota' ? 'Belum ada anggota dengan akun portal.' : 'Belum ada akun petugas.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        {{ $rows->links() }}
    </div>

    @if ($target && $sel)
        <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="imp-title"
             x-data x-on:keydown.escape.window="$wire.cancel()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="cancel"></div>
            <form wire:submit="start" class="relative w-full max-w-md space-y-3 rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                <h2 id="imp-title" class="font-semibold">Masuk sebagai {{ $sel->name }}?</h2>
                <p class="text-sm text-stone-600">
                    @if ($target['type'] === 'anggota')
                        Anda akan membuka <strong>portal anggota</strong> sebagai {{ $sel->name }} ({{ $sel->member_code }}).
                    @else
                        Anda akan memakai aplikasi sebagai <strong>{{ $roleChips($sel->roleNames()) }}</strong> — menu & hak aksi mengikuti akun ini.
                    @endif
                    Sesi berakhir otomatis setelah {{ $maxMinutes }} menit.
                </p>
                <label class="block text-sm"><span class="mb-1 block font-medium text-stone-700">Alasan</span>
                    <textarea wire:model="reason" rows="2" maxlength="{{ \App\Support\ImpersonationPolicy::REASON_MAX }}" placeholder="mis. kasir melapor struk gagal tercetak, saya periksa layarnya"
                              class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"></textarea>
                    @error('reason') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="flex items-start gap-2 text-sm text-stone-700">
                    <input type="checkbox" wire:model="acknowledged" class="mt-0.5 size-4 rounded border-stone-300 text-emerald-700">
                    <span>Saya memahami semua aksi selama sesi ini tercatat atas nama saya.</span>
                </label>
                @error('acknowledged') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancel" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                    <button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Masuk sebagai {{ \Illuminate\Support\Str::of($sel->name)->words(2, '') }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
