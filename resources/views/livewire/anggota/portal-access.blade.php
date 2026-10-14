@php $m = $this->member; $active = $m->hasPortalAccess(); @endphp
<section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="pa-title" data-portal-access>
    <div class="flex items-center justify-between gap-2">
        <h2 id="pa-title" class="font-semibold">Akses portal anggota</h2>
        <span @class(['rounded-md px-2 py-0.5 text-[11px] font-semibold', 'bg-emerald-100 text-emerald-800' => $active, 'bg-stone-100 text-stone-600' => ! $active])>{{ $active ? 'Aktif' : 'Belum aktif' }}</span>
    </div>
    <dl class="mt-2 space-y-1 text-xs text-stone-600">
        <div>Masuk dengan kode <span class="font-mono text-stone-900">{{ $m->member_code }}</span>{{ $m->email ? ' atau '.$m->email : '' }}</div>
        @if ($active)
            <div>PIN transaksi: {{ $m->hasPin() ? 'sudah diatur' : 'belum diatur' }} · Terakhir masuk: {{ $m->portal_last_login_at?->translatedFormat('d M Y H:i') ?? 'belum pernah' }}</div>
        @endif
    </dl>

    @if ($issued)
        <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm" role="status" data-issued>
            <p class="font-semibold text-amber-900">Serahkan ke anggota sekarang — tidak bisa dilihat lagi.</p>
            <dl class="mt-1 grid grid-cols-[6rem_1fr] gap-y-1">
                <dt class="text-amber-800">Kode</dt><dd class="font-mono">{{ $issued['code'] }}</dd>
                @if ($issued['password'])<dt class="text-amber-800">Kata sandi</dt><dd class="font-mono text-base tracking-wide" data-issued-password>{{ $issued['password'] }}</dd>@endif
                @if ($issued['pin'])<dt class="text-amber-800">PIN</dt><dd class="font-mono text-base tracking-[0.3em]" data-issued-pin>{{ $issued['pin'] }}</dd>@endif
            </dl>
            <button type="button" wire:click="dismiss" class="mt-2 text-xs font-semibold text-amber-900 hover:underline">Sudah diserahkan</button>
        </div>
    @endif

    @if ($this->canManage)
        @if ($editing || ! $active)
            <div class="mt-3 space-y-2">
                <label class="block text-xs"><span class="mb-1 block font-medium text-stone-700">Email (opsional, untuk masuk)</span>
                    <input type="email" wire:model="email" maxlength="120" class="w-full rounded-lg border border-stone-300 px-2.5 py-1.5 text-sm">
                    @error('email') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror</label>
                <button type="button" wire:click="enable" @disabled(! $m->is_active) class="w-full rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                    {{ $active ? 'Buat kata sandi & PIN baru' : 'Aktifkan akses portal' }}</button>
            </div>
        @else
            <div class="mt-3 flex flex-wrap gap-2 text-sm">
                <button type="button" wire:click="$set('editing', true)" class="rounded-lg border border-stone-300 px-3 py-1.5 font-medium text-stone-700 hover:bg-stone-50">Atur ulang kata sandi</button>
                <button type="button" wire:click="resetPin" class="rounded-lg border border-stone-300 px-3 py-1.5 font-medium text-stone-700 hover:bg-stone-50">Atur ulang PIN</button>
                <button type="button" wire:click="disable" wire:confirm="Cabut akses portal {{ $m->name }}? Anggota langsung keluar." class="rounded-lg px-3 py-1.5 font-medium text-red-700 hover:bg-red-50">Cabut akses</button>
            </div>
        @endif
    @endif
</section>
