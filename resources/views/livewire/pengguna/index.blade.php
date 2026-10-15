@php
    $roles = \App\Models\Role::LABELS;
    $initials = fn ($name) => collect(explode(' ', preg_replace('/^(Pak|Bu)\s+/', '', $name)))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->join('');
    $roleBadge = ['admin' => 'bg-amber-100 text-amber-900 ring-amber-200', 'kasir' => 'bg-emerald-100 text-emerald-800 ring-emerald-200', 'pengurus' => 'bg-sky-100 text-sky-800 ring-sky-200'];
    $me = $this->currentUserId();
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">Kelola Pengguna</h1>
                <p class="text-xs text-stone-500">Akun petugas koperasi, peran, dan status masuknya.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
            @if (auth()->user()->canOpen('audit'))
                <a href="{{ route('audit.perubahan', ['jenis' => 'pengguna', 'periode' => 'semua']) }}" wire:navigate class="rounded-xl border border-stone-300 bg-white px-3 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Riwayat perubahan akun</a>
            @endif
            <button type="button" wire:click="create"
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Tambah pengguna
            </button>
            </div>
        </div>
        <x-pengguna.nav active="pengguna" />
    </header>

    <div class="grid gap-5 px-4 py-5 sm:px-6 xl:grid-cols-[minmax(0,1fr)_18rem]">
        <div class="min-w-0 space-y-4">
            @if ($flash)
                <p class="rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900" role="status">{{ $flash }}</p>
            @endif
            @if ($error)
                <p class="rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-800" role="alert">{{ $error }}</p>
            @endif

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <p class="text-xs text-stone-500">Akun aktif</p>
                    <p class="text-2xl font-bold tabular-nums">{{ $this->stats['active'] }}</p>
                    @if ($this->stats['inactive'])<p class="text-xs text-stone-400">{{ $this->stats['inactive'] }} nonaktif</p>@endif
                </div>
                @foreach ($roles as $key => $label)
                    <div class="rounded-xl border border-stone-200 bg-white px-4 py-3" data-role-count="{{ $key }}">
                        <p class="text-xs text-stone-500">{{ $label }}</p>
                        <p class="text-2xl font-bold tabular-nums">{{ $this->stats[$key] }}</p>
                    </div>
                @endforeach
            </div>
            @if ($this->stats['no_role'] ?? 0)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-900" role="alert" data-no-role-alert>
                    <span><strong>{{ $this->stats['no_role'] }} akun tanpa peran</strong> — tidak bisa membuka menu apa pun. Tetapkan minimal satu peran.</span>
                    <button type="button" wire:click="$set('role', 'tanpa')" class="rounded-lg border border-red-300 px-3 py-1 text-xs font-semibold hover:bg-white/60">Tampilkan</button>
                </div>
            @endif
            @if ($this->stats['multi'] ?? 0)
                <p class="text-xs text-stone-500" data-multi-count>{{ $this->stats['multi'] }} akun memegang lebih dari satu peran (dihitung di setiap perannya).</p>
            @endif

            <div class="flex flex-col gap-2 sm:flex-row">
                <label class="relative flex-1">
                    <span class="sr-only">Cari pengguna</span>
                    <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" wire:model.live.debounce.200ms="search" placeholder="Cari nama atau email…"
                           class="w-full rounded-lg border border-stone-300 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15">
                </label>
                <div class="flex gap-1 rounded-lg bg-stone-200/60 p-1" role="group" aria-label="Saring peran">
                    @foreach (['' => 'Semua'] + $roles + (($this->stats['no_role'] ?? 0) || $role === 'tanpa' ? ['tanpa' => 'Tanpa peran'] : []) as $key => $label)
                        <button type="button" wire:click="$set('role', '{{ $key }}')" aria-pressed="{{ $role === $key ? 'true' : 'false' }}"
                                @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-white text-stone-900 shadow-sm' => $role === $key, 'text-stone-600 hover:text-stone-900' => $role !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if (count($this->users) === 0)
                <div class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-12 text-center text-sm text-stone-500">Tidak ada pengguna yang cocok.</div>
            @else
                <ul class="divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 bg-white">
                    @foreach ($this->users as $u)
                        @php $names = $u->roleNames(); @endphp
                        <li wire:key="user-{{ $u->id }}" @class(['flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:flex-nowrap', 'bg-stone-50' => ! $u->is_active && $names !== [], 'bg-red-50/60' => $names === []]) @if ($names === []) data-no-role="{{ $u->id }}" @endif>
                            <span @class(['grid size-10 shrink-0 place-items-center rounded-full text-sm font-semibold',
                                          'bg-emerald-950 text-amber-300' => $u->is_active, 'bg-stone-200 text-stone-500' => ! $u->is_active])>{{ $initials($u->name) }}</span>
                            <div class="min-w-0 flex-1">
                                <p @class(['truncate font-medium', 'text-stone-400' => ! $u->is_active])>
                                    {{ $u->name }}
                                    @if ($u->id === $me)<span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-medium text-emerald-800">Anda</span>@endif
                                </p>
                                <p class="truncate text-xs text-stone-500">{{ $u->email }}</p>
                            </div>
                            <span class="flex w-44 shrink-0 flex-wrap gap-1" data-user-roles="{{ implode(',', $names) }}" aria-label="Peran {{ implode(', ', array_map(fn ($n) => $roles[$n] ?? $n, $names)) }}">
                                @forelse ($names as $n)
                                    <span class="rounded-full px-2.5 py-0.5 text-center text-xs font-semibold ring-1 {{ $roleBadge[$n] ?? '' }}">{{ $roles[$n] ?? $n }}</span>
                                @empty
                                    <span class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-red-200">Tanpa peran</span>
                                @endforelse
                            </span>
                            <p class="w-36 text-xs text-stone-500 max-sm:order-last max-sm:w-full">
                                @if (! $u->is_active)
                                    <span class="font-medium text-stone-500">Nonaktif</span>
                                @elseif ($u->last_login_at)
                                    Masuk {{ $u->last_login_at->diffForHumans() }}
                                @else
                                    Belum pernah masuk
                                @endif
                            </p>
                            <div class="flex shrink-0 gap-1">
                                <button type="button" wire:click="edit({{ $u->id }})" @class(['rounded-lg px-2.5 py-1.5 text-sm font-medium', 'bg-red-600 text-white hover:bg-red-700' => $names === [], 'text-emerald-800 hover:bg-emerald-50' => $names !== []]) aria-label="Ubah akun {{ $u->name }}">{{ $names === [] ? 'Atur peran' : 'Ubah' }}</button>
                                <button type="button" wire:click="editRoles({{ $u->id }})" class="rounded-lg px-2.5 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100" aria-label="Ubah peran {{ $u->name }}" data-edit-roles="{{ $u->id }}">Peran</button>
                                @unless ($u->id === $me)
                                    <button type="button" wire:click="toggleActive({{ $u->id }})"
                                            wire:confirm="{{ $u->is_active ? 'Nonaktifkan '.$u->name.'? Akun ini langsung keluar dari semua perangkat.' : 'Aktifkan kembali '.$u->name.'?' }}"
                                            @class(['rounded-lg px-2.5 py-1.5 text-sm font-medium', 'text-red-700 hover:bg-red-50' => $u->is_active, 'text-stone-700 hover:bg-stone-100' => ! $u->is_active])>
                                        {{ $u->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </button>
                                @endunless
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Hak akses peran (bisa diubah) --}}
        <aside class="relative isolate h-fit overflow-hidden rounded-xl bg-emerald-950 p-4 text-emerald-50" aria-labelledby="role-access-title">
            <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.07]" aria-hidden="true"></div>
            <h2 id="role-access-title" class="font-display text-lg font-bold text-white">Hak akses peran</h2>
            <p class="text-xs text-emerald-300">Menu yang boleh dibuka tiap peran.</p>
            <div class="mt-3 space-y-3 text-sm">
                @foreach ($this->roles as $r)
                    <div wire:key="role-{{ $r->name }}" class="rounded-lg bg-emerald-900/60 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-semibold text-amber-300">{{ $r->label }}</p>
                            @if ($permissionsRole !== $r->name)
                                <button type="button" wire:click="editPermissions('{{ $r->name }}')" class="text-xs font-medium text-emerald-200 underline-offset-2 hover:text-white hover:underline">Ubah</button>
                            @endif
                        </div>
                        @if ($permissionsRole === $r->name)
                            <div class="mt-2 space-y-1.5">
                                @foreach ($menus as $key => $m)
                                    @php
                                        $locked = $r->name === 'admin' && in_array($key, \App\Models\Role::ADMIN_REQUIRED, true);
                                        $forbidden = in_array($key, \App\Models\Role::FORBIDDEN[$r->name] ?? [], true);
                                    @endphp
                                    <label @class(['flex items-start gap-2 text-xs', 'opacity-60' => $locked || $forbidden]) @if ($forbidden) data-forbidden-menu="{{ $key }}" @endif>
                                        <input type="checkbox" value="{{ $key }}" wire:model="permissions.{{ $r->name }}" @disabled($locked || $forbidden) class="mt-0.5 size-3.5 accent-amber-400">
                                        <span><span class="font-medium text-white">{{ $m['label'] }}</span> <span class="text-emerald-300">· {{ $forbidden ? 'khusus pengurus & admin' : $m['description'] }}</span></span>
                                    </label>
                                @endforeach
                                @error('permissions') <p class="text-xs text-red-300">{{ $message }}</p> @enderror
                                <div class="flex gap-2 pt-1">
                                    <button type="button" wire:click="savePermissions" class="rounded-lg bg-amber-400 px-3 py-1.5 text-xs font-semibold text-emerald-950 hover:bg-amber-300">Simpan</button>
                                    <button type="button" wire:click="cancelPermissions" class="rounded-lg px-3 py-1.5 text-xs text-emerald-200 hover:text-white">Batal</button>
                                </div>
                            </div>
                        @else
                            <p class="mt-1 text-xs leading-relaxed text-emerald-100">{{ collect($r->permissions)->map(fn ($k) => $menus[$k]['label'] ?? $k)->join(' · ') ?: 'Belum ada menu' }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </aside>
    </div>

    @include('livewire.pengguna.partials.user-form')
    @include('livewire.pengguna.partials.role-form')
</div>
