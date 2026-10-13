@use('App\Livewire\Pengguna\Locked')
@php
    $roles = \App\Models\Role::LABELS;
    $s = $this->summary;
    $levelTone = [1 => 'bg-amber-100 text-amber-900', 2 => 'bg-orange-100 text-orange-900', 3 => 'bg-red-100 text-red-800'];
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Kelola Pengguna</h1>
                <p class="text-xs text-stone-500">Akun yang dikunci sementara karena salah kata sandi berulang.</p>
            </div>
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">Data contoh</span>
        </div>
        <x-pengguna.nav active="pengguna.terkunci" />
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 xl:grid-cols-[minmax(0,1fr)_18rem]">
        <div class="min-w-0 space-y-4">
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Sedang terkunci</p>
                    <p class="text-2xl font-bold tabular-nums">{{ $s['total'] }}</p>
                </div>
                <div @class(['rounded-xl border px-4 py-3', 'border-red-200 bg-red-50' => $s['manual'] > 0, 'border-stone-200 bg-white' => $s['manual'] === 0])>
                    <p class="text-xs text-stone-500">Menunggu dibuka Admin</p>
                    <p class="text-2xl font-bold tabular-nums">{{ $s['manual'] }}</p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Kunci sementara</p>
                    <p class="text-2xl font-bold tabular-nums">{{ $s['temporary'] }}</p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Email tak terdaftar</p>
                    <p class="text-2xl font-bold tabular-nums">{{ $s['unknown'] }}</p>
                </div>
            </div>

            <ul class="space-y-3" aria-label="Daftar akun terkunci">
                @forelse ($this->accounts as $a)
                    @php $left = Locked::minutesLeft($a); @endphp
                    <li wire:key="lock-{{ $a['id'] }}" class="rounded-xl border border-stone-200 bg-white p-4">
                        <div class="flex flex-wrap items-start gap-3">
                            <span @class(['grid size-10 shrink-0 place-items-center rounded-full', 'bg-red-100 text-red-700' => $a['level'] >= 3, 'bg-amber-100 text-amber-800' => $a['level'] < 3])>
                                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    @if ($a['name'])
                                        <span class="font-semibold">{{ $a['name'] }}</span>
                                        <span class="text-xs text-stone-500">{{ $roles[$a['role']] ?? $a['role'] }}</span>
                                    @else
                                        <span class="font-semibold">Email tidak terdaftar</span>
                                    @endif
                                    <span class="rounded-md px-2 py-0.5 text-xs font-semibold {{ $levelTone[$a['level']] }}">Tingkat {{ $a['level'] }}</span>
                                </p>
                                <p class="font-mono text-xs break-all text-stone-600">{{ $a['email'] }}</p>
                                <p class="mt-1.5 text-sm">
                                    <span class="font-semibold tabular-nums">{{ $a['failed'] }}×</span> salah kata sandi ·
                                    @if ($left === null)
                                        <span class="font-medium text-red-700">terkunci sampai dibuka Admin</span>
                                    @else
                                        <span class="font-medium text-amber-800">terbuka otomatis {{ $left <= 1 ? 'dalam ±1 menit' : 'dalam '.$left.' menit' }}</span>
                                    @endif
                                </p>
                                <p class="mt-0.5 text-xs text-stone-500">
                                    Dikunci {{ \Illuminate\Support\Carbon::parse($a['locked_at'])->diffForHumans() }} · percobaan terakhir {{ \Illuminate\Support\Carbon::parse($a['last_attempt_at'])->diffForHumans() }}
                                </p>
                                <p class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($a['ip_addresses'] as $ip)
                                        <span class="rounded bg-stone-100 px-1.5 py-0.5 font-mono text-[11px] text-stone-600">{{ $ip }}</span>
                                    @endforeach
                                    @if (count($a['ip_addresses']) > 1)
                                        <span class="text-[11px] text-amber-800">dari {{ count($a['ip_addresses']) }} alamat berbeda</span>
                                    @endif
                                </p>
                                @unless ($a['user_id'])
                                    <p class="mt-1.5 text-xs text-stone-500">Tidak ada akun dengan email ini — kemungkinan tebakan dari luar. Membuka kunci hanya mengizinkan percobaan lagi.</p>
                                @endunless
                            </div>
                            <button type="button" wire:click="confirmUnlock({{ $a['id'] }})"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-emerald-700 px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-50">
                                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 017.6-1.8"/></svg>
                                Buka kunci
                            </button>
                        </div>
                    </li>
                @empty
                    <li class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-14 text-center">
                        <p class="text-sm font-medium text-stone-600">Tidak ada akun yang terkunci</p>
                        <p class="mt-1 text-xs text-stone-500">Semua petugas dapat masuk seperti biasa.</p>
                    </li>
                @endforelse
            </ul>
        </div>

        <aside class="h-fit space-y-3 rounded-xl border border-stone-200 bg-white p-4" aria-labelledby="tiers-title">
            <h2 id="tiers-title" class="font-semibold">Aturan kunci bertingkat</h2>
            <ol class="space-y-2 text-sm">
                @foreach (Locked::tiers() as $i => $t)
                    <li class="flex items-center gap-2">
                        <span class="shrink-0 rounded-md px-2 py-0.5 text-xs font-semibold whitespace-nowrap {{ $levelTone[$i + 1] }}">Tingkat {{ $i + 1 }}</span>
                        <span>{{ $t['failed'] }}× gagal → <span class="font-medium">{{ $t['label'] }}</span></span>
                    </li>
                @endforeach
            </ol>
            <p class="text-xs text-stone-500">Hitungan gagal kembali ke nol setelah berhasil masuk. Setiap kunci & pembukaan tercatat di Audit.</p>
            <a href="{{ route('audit', ['tampil' => 'login']) }}" wire:navigate class="inline-block text-sm font-semibold text-emerald-800 hover:underline">Lihat riwayat login →</a>
        </aside>
    </div>

    {{-- Konfirmasi buka kunci --}}
    @if ($t = $this->target)
        <div class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="unlock-title"
             x-data x-on:keydown.escape.window="$wire.cancelUnlock()">
            <div class="absolute inset-0 bg-stone-950/50" wire:click="cancelUnlock"></div>
            <form wire:submit="unlock" class="relative w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
                <h2 id="unlock-title" class="font-semibold">Buka kunci {{ $t['name'] ?? $t['email'] }}?</h2>
                <p class="mt-1 text-sm text-stone-600">Hitungan gagal ({{ $t['failed'] }}×) dihapus dan <span class="font-mono text-xs">{{ $t['email'] }}</span> dapat mencoba masuk lagi.</p>
                @if (count($t['ip_addresses']) > 1 || ! $t['user_id'])
                    <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Percobaan datang dari {{ count($t['ip_addresses']) }} alamat IP. Pastikan pemilik akun memang yang mencoba masuk; bila ragu, ganti kata sandinya dulu.</p>
                @endif
                <label class="mt-3 block text-sm">
                    <span class="font-medium">Catatan @if ($t['level'] < 3)<span class="font-normal text-stone-500">(opsional)</span>@endif</span>
                    <textarea wire:model="note" rows="2" maxlength="200" placeholder="mis. Sudah dikonfirmasi lewat telepon"
                              @class(['mt-1 w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-4', 'border-red-400 focus:ring-red-500/15' => $errors->has('note'), 'border-stone-300 focus:border-emerald-600 focus:ring-emerald-600/15' => ! $errors->has('note')])></textarea>
                    @error('note') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="cancelUnlock" class="rounded-xl px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100">Batal</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="unlock" class="rounded-xl bg-emerald-800 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-900">Buka kunci</button>
                </div>
            </form>
        </div>
    @endif
</div>
