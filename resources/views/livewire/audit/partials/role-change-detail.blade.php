{{-- Panel rincian satu catatan perubahan peran ($detail dari RoleImpersonationTrail::roleChangeDetail). IP & perangkat hanya untuk Admin. --}}
@php
    $roleLabels = \App\Models\Role::LABELS;
    $chips = fn (array $names, string $cls) => collect($names)->map(fn ($r) => '<span class="rounded px-1.5 py-0.5 text-xs font-medium '.$cls.'">'.e($roleLabels[$r] ?? (\App\Support\Menu::ITEMS[$r]['label'] ?? $r)).'</span>')->join(' ');
    $viewerIsAdmin = \Illuminate\Support\Facades\Auth::guard('web')->user()?->hasRole('admin');
@endphp
<div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-labelledby="rc-title" x-data x-on:keydown.escape.window="$wire.closeDetail()" data-role-detail="{{ $detail['id'] }}">
    <div class="absolute inset-0 bg-stone-950/40" wire:click="closeDetail"></div>
    <aside class="relative flex h-full w-full max-w-md flex-col overflow-y-auto bg-white shadow-xl">
        <div class="flex items-start gap-3 border-b border-stone-200 px-5 py-4">
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold tracking-wider text-stone-500 uppercase">{{ ['akun' => 'Perubahan peran akun', 'peran' => 'Perubahan hak menu peran', 'perbaikan' => 'Perbaikan konsistensi peran'][$detail['kind']] }}</p>
                <h2 id="rc-title" class="mt-0.5 font-semibold">{{ $detail['description'] }}</h2>
                <p class="mt-0.5 text-xs text-stone-500">{{ $detail['at']->translatedFormat('l, d M Y H:i:s') }}</p>
            </div>
            <button type="button" wire:click="closeDetail" class="grid size-8 place-items-center rounded-lg text-stone-500 hover:bg-stone-100" aria-label="Tutup">✕</button>
        </div>

        <div class="space-y-5 px-5 py-4 text-sm">
            <p @class(['rounded-lg px-3 py-2 text-xs font-medium', 'bg-emerald-50 text-emerald-800' => $detail['valid'], 'bg-red-50 text-red-800' => ! $detail['valid']]) data-detail-seal="{{ $detail['valid'] ? 'utuh' : 'rusak' }}">
                {{ $detail['valid'] ? '✓ Catatan utuh — checksum HMAC cocok.' : '✗ Catatan tidak cocok dengan checksum HMAC-nya — kemungkinan diubah di luar aplikasi.' }}
            </p>

            <dl class="grid grid-cols-[7rem_minmax(0,1fr)] gap-x-3 gap-y-2">
                <dt class="text-stone-500">Pelaku</dt>
                <dd><span class="font-medium">{{ $detail['actor']['name'] }}</span>@if ($detail['actor']['role']) · {{ $roleLabels[$detail['actor']['role']] ?? $detail['actor']['role'] }}@endif
                    @if ($detail['impersonation'])
                        <span class="mt-1 block rounded bg-red-50 px-2 py-1 text-xs text-red-800" data-detail-impersonation>Dilakukan saat impersonasi — sebagai {{ $detail['impersonation']['target_name'] }}</span>
                    @endif
                    @if ($detail['automatic'])<span class="mt-1 block text-xs text-amber-700">Di luar halaman Pengguna (tercatat otomatis)</span>@endif
                </dd>
                @if ($detail['subject']['label'])
                    <dt class="text-stone-500">{{ $detail['kind'] === 'peran' ? 'Peran' : 'Akun' }}</dt>
                    <dd class="font-medium">{{ $detail['subject']['label'] }}</dd>
                @endif
                @if ($detail['reason'])
                    <dt class="text-stone-500">Alasan</dt><dd>{{ $detail['reason'] }}</dd>
                @endif
                @if ($viewerIsAdmin && ($detail['actor']['ip'] || $detail['actor']['agent']))
                    <dt class="text-stone-500">Perangkat</dt>
                    <dd class="text-xs text-stone-600" data-detail-device>{{ $detail['actor']['ip'] }}<span class="block truncate" title="{{ $detail['actor']['agent'] }}">{{ $detail['actor']['agent'] }}</span></dd>
                @endif
            </dl>

            @if ($detail['kind'] === 'akun')
                <section aria-labelledby="rc-roles">
                    <h3 id="rc-roles" class="mb-2 text-xs font-semibold tracking-wider text-stone-500 uppercase">Peran</h3>
                    <div class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 rounded-lg border border-stone-200 p-3">
                        <div data-detail-before>{!! $chips($detail['before'], 'bg-stone-100 text-stone-700') ?: '<span class="text-xs text-stone-400">—</span>' !!}</div>
                        <span class="text-stone-400" aria-label="menjadi">→</span>
                        <div data-detail-after>{!! $chips($detail['after'], 'bg-emerald-100 text-emerald-800') ?: '<span class="text-xs text-stone-400">—</span>' !!}</div>
                    </div>
                </section>
            @endif

            @if ($detail['added'] || $detail['removed'])
                <section aria-labelledby="rc-diff">
                    <h3 id="rc-diff" class="mb-2 text-xs font-semibold tracking-wider text-stone-500 uppercase">{{ $detail['kind'] === 'peran' ? 'Menu' : 'Perubahan' }}</h3>
                    <ul class="space-y-1">
                        @foreach ($detail['added'] as $r)<li class="text-emerald-800" data-detail-added>+ {{ $roleLabels[$r] ?? (\App\Support\Menu::ITEMS[$r]['label'] ?? $r) }} ditambahkan</li>@endforeach
                        @foreach ($detail['removed'] as $r)<li class="text-red-800" data-detail-removed>− {{ $roleLabels[$r] ?? (\App\Support\Menu::ITEMS[$r]['label'] ?? $r) }} dicabut</li>@endforeach
                    </ul>
                </section>
            @endif

            @if ($detail['menu_impact'])
                <section aria-labelledby="rc-menu" class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600" data-detail-menu-impact>
                    <h3 id="rc-menu" class="font-semibold text-stone-800">Dampak pada menu gabungan</h3>
                    <p class="mt-1">Menu baru: {{ implode(', ', $detail['menu_impact']['gained']) ?: '—' }}</p>
                    <p>Menu hilang: {{ implode(', ', $detail['menu_impact']['lost']) ?: '—' }}</p>
                    <p class="mt-1 text-stone-500">Dihitung dengan hak menu peran saat ini · total {{ $detail['menu_impact']['total_after'] }} menu sesudahnya.</p>
                </section>
            @endif

            @if ($detail['kind'] === 'peran')
                <section class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600">
                    <p><span class="font-medium text-stone-800">Sebelum:</span> {{ $detail['menus_before'] ?: '—' }}</p>
                    <p class="mt-1"><span class="font-medium text-stone-800">Sesudah:</span> {{ $detail['menus_after'] ?: '—' }}</p>
                    @if ($detail['holders'] !== null)<p class="mt-1 text-stone-500">Berlaku untuk {{ $detail['holders'] }} akun yang kini memegang peran ini.</p>@endif
                </section>
            @endif

            @if ($detail['repair'])
                <section class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600">
                    <p>Perbaikan diterapkan: {{ $detail['repair']['fixed'] ?? 0 }} · peran utama belum di pivot: {{ count($detail['repair']['missing_pivot'] ?? []) }} · peran utama salah: {{ count($detail['repair']['wrong_primary'] ?? []) }} · tanpa peran: {{ count($detail['repair']['no_role'] ?? []) }}</p>
                </section>
            @endif

            <p class="text-[11px] text-stone-400">Catatan #{{ $detail['id'] }} · {{ $detail['event'] }}</p>
        </div>
    </aside>
</div>
