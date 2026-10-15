{{--
    Dialog alasan mulai impersonasi. Dipakai komponen Pengguna\Impersonation: $target (jenis & id), $this->targetModel,
    $this->preview, $presets, $durations, properti reason/minutes/acknowledged, aksi usePreset/start/cancel.
--}}
@php $sel = $this->targetModel; @endphp
@if ($target && $sel)
    @php
        $isMember = $target['type'] === 'anggota';
        $preview = $this->preview;
        $roleText = $isMember ? null : collect($sel->roleNames())->map(fn ($r) => \App\Models\Role::LABELS[$r] ?? $r)->join(' · ');
    @endphp
    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="imp-title" aria-describedby="imp-desc"
         x-data x-on:keydown.escape.window="$wire.cancel()" data-impersonate-dialog>
        <div class="absolute inset-0 bg-stone-950/50" wire:click="cancel"></div>
        <form wire:submit="start" class="relative max-h-[92vh] w-full max-w-lg space-y-4 overflow-x-hidden overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
            <div class="flex items-start gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-amber-100 text-amber-800" aria-hidden="true">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM17 11l2 2 4-4"/></svg>
                </span>
                <div class="min-w-0">
                    <h2 id="imp-title" class="font-semibold">Masuk sebagai {{ $sel->name }}?</h2>
                    <p id="imp-desc" class="text-sm text-stone-600">
                        @if ($isMember)
                            Portal anggota · {{ $sel->member_code }}{{ $sel->class ? ' · '.$sel->class : '' }}
                        @else
                            Petugas · {{ $roleText }} · {{ $sel->email }}
                        @endif
                    </p>
                </div>
            </div>

            {{-- Yang akan terlihat --}}
            <div class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600" data-impersonate-preview>
                <p class="font-medium text-stone-800">{{ $preview['kind'] === 'menu' ? 'Menu yang akan Anda lihat ('.count($preview['items']).')' : 'Yang akan Anda lihat di portal' }}</p>
                <p class="mt-0.5">{{ implode(' · ', $preview['items']) ?: '—' }}</p>
            </div>

            {{-- Riwayat impersonasi akun ini --}}
            @if ($this->targetHistory->isNotEmpty())
                <div class="rounded-lg border border-stone-200 px-3 py-2 text-xs" data-target-history>
                    <p class="font-medium text-stone-800">Riwayat impersonasi akun ini</p>
                    <ul class="mt-1 space-y-1 text-stone-600">
                        @foreach ($this->targetHistory as $h)
                            <li wire:key="h-{{ $h->id }}">
                                @if ($h->isActive())<span class="mr-1 rounded bg-red-100 px-1 font-semibold text-red-800" data-history-active>sedang berlangsung</span>@endif
                                <span class="font-medium">{{ $h->admin_name }}</span> · {{ $h->started_at->diffForHumans() }}
                                @if ($h->ended_at) · {{ $h->durationMinutes() }} menit @endif
                                <span class="block truncate text-stone-500" title="{{ $h->reason }}">“{{ $h->reason }}”</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Alasan --}}
            <div>
                <label for="imp-reason" class="mb-1 block text-sm font-medium text-stone-700">Alasan <span class="font-normal text-stone-500">(dicatat di jejak audit)</span></label>
                @if ($presets)
                    <div class="mb-2 flex flex-wrap gap-1.5" role="group" aria-label="Alasan cepat">
                        @foreach ($presets as $i => $preset)
                            <button type="button" wire:click="usePreset({{ $i }})" data-reason-preset
                                    class="rounded-full border border-stone-300 px-2.5 py-1 text-xs text-stone-700 hover:border-emerald-600 hover:bg-emerald-50">{{ $preset }}</button>
                        @endforeach
                    </div>
                @endif
                <div x-data="{ get len() { return ($wire.reason || '').length } }">
                    <textarea id="imp-reason" x-init="$nextTick(() => $el.focus())" wire:model="reason" rows="3" maxlength="{{ \App\Support\ImpersonationPolicy::REASON_MAX }}"
                              placeholder="{{ $isMember ? 'mis. anggota tidak bisa melihat saldo simpanan di portal' : 'mis. kasir melapor struk gagal tercetak, saya periksa layarnya' }}"
                              @error('reason') aria-invalid="true" aria-describedby="imp-reason-err" @enderror
                              class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"></textarea>
                    <p class="mt-1 text-right text-[11px] text-stone-400 tabular-nums" aria-hidden="true"
                       x-text="len + '/{{ \App\Support\ImpersonationPolicy::REASON_MAX }}'"
                       x-bind:class="len > 0 && len < {{ \App\Support\ImpersonationPolicy::REASON_MIN }} && 'text-red-500'"></p>
                </div>
                @error('reason') <span id="imp-reason-err" class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                <p class="mt-1 text-[11px] text-stone-500" data-reason-audit-note>
                    Alasan, waktu, dan semua aksi Anda tercatat ber-HMAC dan tampil di
                    @if (\App\Support\Menu::canReach('audit.peran'))
                        <a href="{{ route('audit.peran', ['tampil' => 'impersonasi']) }}" wire:navigate class="font-medium text-emerald-800 hover:underline">Audit → Peran & impersonasi</a>.
                    @else
                        Audit → Peran & impersonasi.
                    @endif
                </p>
            </div>

            {{-- Lama sesi --}}
            <fieldset>
                <legend class="mb-1 text-sm font-medium text-stone-700">Lama sesi</legend>
                <div class="flex gap-1.5">
                    @foreach ($durations as $d)
                        <label class="cursor-pointer">
                            <input type="radio" wire:model="minutes" value="{{ $d }}" @checked((int) $minutes === $d) class="peer sr-only">
                            <span class="block rounded-lg border border-stone-300 px-3 py-1.5 text-sm peer-checked:border-emerald-700 peer-checked:bg-emerald-50 peer-checked:font-semibold peer-checked:text-emerald-900 peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-600">{{ $d }} menit</span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-stone-500">Setelah waktunya habis Anda otomatis kembali ke akun sendiri.</p>
                @error('minutes') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </fieldset>

            <label class="flex items-start gap-2 text-sm text-stone-700">
                <input type="checkbox" wire:model="acknowledged" class="mt-0.5 size-4 rounded border-stone-300 text-emerald-700">
                <span>Saya memahami semua aksi selama sesi ini tercatat atas nama saya{{ $isMember ? ', dan saya tidak akan mengubah PIN/kata sandi anggota' : '' }}.</span>
            </label>
            @error('acknowledged') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror

            <div class="flex justify-end gap-2">
                <button type="button" wire:click="cancel" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Batal</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="start" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">
                    <span wire:loading.remove wire:target="start">Mulai sesi</span>
                    <span wire:loading wire:target="start">Memulai…</span>
                </button>
            </div>
        </form>
    </div>
@endif
