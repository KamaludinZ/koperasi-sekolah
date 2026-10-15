@php
    $roleLabels = \App\Models\Role::LABELS;
    $roleText = fn ($names) => collect((array) $names)->map(fn ($r) => $roleLabels[$r] ?? $r)->join(', ');
    $sealBadge = function (?int $id) use ($integrity) {
        if ($id === null || ! array_key_exists($id, $integrity)) {
            return null;
        }

        return $integrity[$id];
    };
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="border-b border-stone-200 bg-white px-4 pt-4 sm:px-6">
        <h1 class="text-lg font-semibold">Audit & Riwayat</h1>
        <p class="text-xs text-stone-500">Siapa mengubah peran siapa, siapa masuk sebagai siapa dan apa yang dilakukannya — tercatat atas nama admin pelaku dan dijaga checksum HMAC.</p>
        <x-audit.nav active="audit.peran" />
    </header>

    <div class="space-y-4 px-4 py-5 sm:px-6">
        {{-- Ringkasan --}}
        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Ringkasan">
            <div class="rounded-xl border border-stone-200 bg-white p-4"><p class="text-xs text-stone-500">Perubahan peran</p><p class="mt-1 text-2xl font-semibold tabular-nums" data-stat="role_changes">{{ $stats['role_changes'] }}</p></div>
            <div class="rounded-xl border border-stone-200 bg-white p-4"><p class="text-xs text-stone-500">Sesi impersonasi</p><p class="mt-1 text-2xl font-semibold tabular-nums" data-stat="sessions">{{ $stats['sessions'] }}</p></div>
            <div @class(['rounded-xl border p-4', 'border-red-200 bg-red-50' => $stats['active'] > 0, 'border-stone-200 bg-white' => $stats['active'] === 0])>
                <p class="text-xs text-stone-500">Sedang berlangsung</p><p @class(['mt-1 text-2xl font-semibold tabular-nums', 'text-red-800' => $stats['active'] > 0]) data-stat="active">{{ $stats['active'] }}</p>
            </div>
            <div @class(['rounded-xl border p-4', 'border-red-300 bg-red-50' => $stats['invalid'] > 0, 'border-emerald-200 bg-emerald-50' => $stats['invalid'] === 0])>
                <p class="text-xs text-stone-500">Integritas catatan (HMAC)</p>
                <p @class(['mt-1 font-semibold', 'text-red-800' => $stats['invalid'] > 0, 'text-emerald-800' => $stats['invalid'] === 0]) data-stat="integrity">
                    {{ $stats['invalid'] > 0 ? $stats['invalid'].' dari '.$stats['checked'].' catatan tidak cocok!' : ($stats['checked'] > 0 ? 'Semua '.$stats['checked'].' catatan utuh' : 'Belum ada catatan') }}
                </p>
            </div>
        </section>

        {{-- Hasil verifikasi integritas seluruh jejak audit --}}
        <section @class(['rounded-xl border p-4', 'border-red-300 bg-red-50' => $verification && ! $verification['ok'], 'border-stone-200 bg-white' => ! $verification || $verification['ok']]) aria-labelledby="verif-title" data-verification-panel>
            <div class="flex flex-wrap items-start gap-3">
                <div class="min-w-0 flex-1">
                    <h2 id="verif-title" class="text-sm font-semibold">Verifikasi integritas log</h2>
                    @if ($verification)
                        <p @class(['mt-0.5 text-sm', 'font-semibold text-red-800' => ! $verification['ok'], 'text-emerald-800' => $verification['ok']]) data-verification-status="{{ $verification['ok'] ? 'utuh' : 'rusak' }}">
                            {{ $verification['ok'] ? '✓ Seluruh jejak audit utuh' : '✗ '.$verification['invalid_count'].' catatan tidak cocok dengan checksum HMAC-nya' }}
                        </p>
                        <p class="mt-0.5 text-xs text-stone-500">
                            {{ \Illuminate\Support\Carbon::parse($verification['at'])->translatedFormat('d M Y H:i') }} ({{ \Illuminate\Support\Carbon::parse($verification['at'])->diffForHumans() }})
                            · {{ $verification['source'] === 'manual' ? 'diperiksa manual'.($verification['by'] ? ' oleh '.$verification['by'] : '') : 'pemeriksaan terjadwal' }}
                            · {{ number_format($verification['checked'], 0, ',', '.') }} catatan @if ($verification['first_id'])(id {{ $verification['first_id'] }}–{{ $verification['last_id'] }})@endif
                            @if ($verification['unsigned']) · {{ $verification['unsigned'] }} catatan lama belum bertanda @endif
                        </p>
                    @else
                        <p class="mt-0.5 text-xs text-stone-500" data-verification-status="belum">Belum pernah diperiksa. Pemeriksaan terjadwal berjalan setiap hari pukul 02.30.</p>
                    @endif
                </div>
                @if (\Illuminate\Support\Facades\Auth::guard('web')->user()?->hasRole('admin'))
                    <button type="button" wire:click="verifyNow" wire:loading.attr="disabled" wire:target="verifyNow"
                            class="rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-60" data-verify-now>
                        <span wire:loading.remove wire:target="verifyNow">Periksa sekarang</span><span wire:loading wire:target="verifyNow">Memeriksa…</span>
                    </button>
                @endif
            </div>
            @if ($invalidRows)
                <ul class="mt-3 divide-y divide-red-100 rounded-lg border border-red-200 bg-white text-xs" data-invalid-rows>
                    @foreach ($invalidRows as $bad)
                        <li class="flex gap-2 px-3 py-1.5"><span class="font-mono text-red-700">#{{ $bad['id'] }}</span><span class="text-stone-500">{{ $bad['at'] }}</span><span class="min-w-0 flex-1 truncate">{{ $bad['event'] }} — {{ $bad['description'] }}</span></li>
                    @endforeach
                    @if ($verification['invalid_count'] > count($invalidRows))<li class="px-3 py-1.5 text-stone-500">… dan {{ $verification['invalid_count'] - count($invalidRows) }} lainnya</li>@endif
                </ul>
            @endif
        </section>

        {{-- Tab & saringan --}}
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex gap-1 rounded-lg bg-stone-200/60 p-0.5 text-sm" role="tablist" aria-label="Jenis jejak">
                @foreach (\App\Livewire\Audit\RoleImpersonation::TABS as $key => $label)
                    <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                            @class(['rounded-md px-3 py-1.5 font-medium', 'bg-white text-stone-900 shadow-sm' => $tab === $key, 'text-stone-600 hover:text-stone-900' => $tab !== $key])>{{ $label }}
                        <span class="ml-1 rounded-full bg-stone-200 px-1.5 text-[11px] text-stone-700 tabular-nums" data-tab-count="{{ $key }}">{{ $counts[$key] }}</span></button>
                @endforeach
            </div>
            <select wire:model.live="period" aria-label="Periode" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                @foreach ($periods as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
            </select>
            <select wire:model.live="actor" aria-label="Pelaku" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">Semua pelaku</option>
                @foreach ($actors as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach
            </select>
            @if ($tab === 'peran')
                <select wire:model.live="kind" aria-label="Jenis perubahan" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                    <option value="">Semua jenis</option>
                    @foreach ($kinds as $k => $def)<option value="{{ $k }}">{{ $def['label'] }}</option>@endforeach
                </select>
            @endif
            @if ($tab === 'aksi')
                <select wire:model.live="category" aria-label="Kategori" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                    <option value="">Semua kategori</option>
                    @foreach ($categories as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                </select>
            @endif
            <label class="flex items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                <input type="checkbox" wire:model.live="seal" value="rusak" class="size-4 rounded border-stone-300 text-red-700"> Hanya yang tidak cocok
            </label>
            @if ($tab === 'impersonasi')
                <select wire:model.live="status" aria-label="Status sesi" class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                    <option value="">Semua sesi</option><option value="aktif">Sedang berlangsung</option><option value="selesai">Sudah berakhir</option>
                </select>
            @endif
            <input type="search" wire:model.live.debounce.300ms="search" maxlength="60" placeholder="Cari nama, akun, alasan…" aria-label="Cari"
                   class="min-w-48 flex-1 rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
            @if ($search !== '' || $actor !== '' || $status !== '' || $kind !== '' || $category !== '' || $seal !== '' || $period !== '30')
                <button type="button" wire:click="resetFilters" class="text-sm font-medium text-emerald-800 hover:underline">Atur ulang</button>
            @endif
        </div>

        <section class="overflow-hidden rounded-xl border border-stone-200 bg-white" aria-label="{{ \App\Livewire\Audit\RoleImpersonation::TABS[$tab] }}">
            <ul class="divide-y divide-stone-100">
                @forelse ($rows as $row)
                    @if ($tab === 'impersonasi')
                        @php
                            $logs = $sessionLogs[$row->id] ?? ['started' => null, 'ended' => null];
                            $chain = $this->trail->chain($row);
                            $live = $row->isActive();
                        @endphp
                        <li class="px-4 py-3" wire:key="imp-{{ $row->id }}" data-session="{{ $row->id }}">
                            <div class="flex flex-wrap items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm"><strong>{{ $row->admin_name }}</strong> masuk sebagai <strong>{{ $row->target_name }}</strong>
                                        <span class="text-stone-500">{{ $row->target_label ? '· '.$row->target_label : '' }} · {{ \App\Models\Impersonation::TARGET_TYPES[$row->target_type] }}</span></p>
                                    <p class="mt-0.5 text-xs text-stone-600">Alasan: {{ $row->reason }}</p>
                                    <p class="mt-0.5 text-xs text-stone-500">
                                        {{ $row->started_at->translatedFormat('d M Y H:i') }}
                                        @if ($row->ended_at) – {{ $row->ended_at->format('H:i') }} · {{ $row->durationMinutes() }} menit · {{ $endReasons[$row->end_reason] ?? $row->end_reason }}
                                        @else · batas {{ $row->expires_at->format('H:i') }} @endif
                                        @if ($row->ip_address) · {{ $row->ip_address }} @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-1">
                                    @if ($live)
                                        <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800">Berlangsung</span>
                                    @elseif (! $row->ended_at)
                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-900">Lewat batas, belum ditutup</span>
                                    @else
                                        <span class="rounded bg-stone-100 px-1.5 py-0.5 text-xs font-medium text-stone-700">Selesai</span>
                                    @endif
                                    <span class="text-[11px] text-stone-500" data-seal="{{ $chain['ok'] ? 'utuh' : ($chain['start'] === null ? 'tanpa' : 'rusak') }}" title="{{ $chain['problem'] ?? 'Catatan mulai & selesai sah dan saling tertaut' }}">
                                        @if ($chain['ok']) <span class="text-emerald-700">✓ {{ $row->ended_at ? 'mulai–selesai tertaut' : 'catatan mulai utuh' }}</span>
                                        @elseif ($chain['start'] === null) <span class="text-amber-700">{{ $chain['problem'] }}</span>
                                        @else <span class="font-semibold text-red-700">✗ {{ $chain['problem'] }}</span>@endif
                                    </span>
                                </div>
                            </div>
                        </li>
                    @else
                        @php
                            $ok = $sealBadge($row->id);
                            $p = $row->properties ?? [];
                            $via = $p['impersonation']['target_name'] ?? null;
                        @endphp
                        <li class="flex flex-wrap items-start gap-2 px-4 py-3" wire:key="log-{{ $row->id }}" data-log="{{ $row->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-stone-900">{{ $row->description }}</p>
                                @if ($tab === 'peran' && (($p['added'] ?? []) || ($p['removed'] ?? [])))
                                    <p class="mt-0.5 flex flex-wrap gap-1 text-xs">
                                        @foreach ((array) ($p['added'] ?? []) as $r)<span class="rounded bg-emerald-100 px-1.5 py-0.5 font-medium text-emerald-800">+ {{ $roleLabels[$r] ?? (\App\Support\Menu::ITEMS[$r]['label'] ?? $r) }}</span>@endforeach
                                        @foreach ((array) ($p['removed'] ?? []) as $r)<span class="rounded bg-red-100 px-1.5 py-0.5 font-medium text-red-800">− {{ $roleLabels[$r] ?? (\App\Support\Menu::ITEMS[$r]['label'] ?? $r) }}</span>@endforeach
                                    </p>
                                @endif
                                <p class="mt-0.5 text-xs text-stone-500">
                                    <span class="font-medium text-stone-700">{{ $row->actor_name ?? 'Sistem' }}</span>@if ($row->actor_role) · {{ $roleLabels[$row->actor_role] ?? $row->actor_role }}@endif
                                    @if ($via)<span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-[11px] font-semibold text-red-800">sebagai {{ $via }}</span>@endif
                                    · {{ $row->created_at->translatedFormat('d M Y H:i') }}@if ($row->subject_label) · {{ $row->subject_label }}@endif
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <span class="text-[11px]" data-seal="{{ $ok === false ? 'rusak' : 'utuh' }}">
                                    @if ($ok === false)<span class="font-semibold text-red-700">✗ tidak cocok</span>@else<span class="text-emerald-700">✓ utuh</span>@endif
                                </span>
                                @if ($tab === 'peran')
                                    <button type="button" wire:click="showDetail({{ $row->id }})" class="text-xs font-medium text-emerald-800 hover:underline" data-open-detail="{{ $row->id }}">Rincian</button>
                                @endif
                            </div>
                        </li>
                    @endif
                @empty
                    <li class="px-4 py-10 text-center text-sm text-stone-400" data-empty>
                        {{ match ($tab) { 'impersonasi' => 'Belum ada sesi impersonasi pada periode ini.', 'aksi' => 'Tidak ada aksi yang dilakukan selama impersonasi pada periode ini.', default => 'Belum ada perubahan peran pada periode ini.' } }}
                    </li>
                @endforelse
            </ul>
        </section>
        {{ $rows->links() }}
    </div>

    @if ($detail)
        @include('livewire.audit.partials.role-change-detail')
    @endif
</div>
