<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex flex-wrap items-end justify-between gap-3 border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Pembaruan Panduan</h1>
            <p class="text-xs text-stone-500">Susun ulang panduan otomatis dari konfigurasi menu, halaman, dan izin peran. Panduan yang disunting manual tidak ditimpa.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('panduan') }}" wire:navigate class="text-sm font-medium text-emerald-800 hover:underline">← Pusat Panduan</a>
            <a href="{{ route('panduan.sunting') }}" wire:navigate class="text-sm font-medium text-emerald-800 hover:underline" data-updates-editor-link>Sunting panduan</a>
            <a href="{{ route('panduan.versi') }}" wire:navigate class="text-sm font-medium text-emerald-800 hover:underline" data-updates-versions-link>Riwayat versi</a>
            <button type="button" wire:click="scanNow" wire:loading.attr="disabled" wire:target="scanNow"
                    wire:confirm="Pindai menu, halaman & izin lalu perbarui semua panduan otomatis sekarang? Panduan manual dan suntingan admin tidak berubah."
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60" data-guide-scan-now>
                <svg class="size-4" wire:loading.class="animate-spin" wire:target="scanNow" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/></svg>
                <span wire:loading.remove wire:target="scanNow">Pindai & perbarui sekarang</span><span wire:loading wire:target="scanNow">Memindai…</span>
            </button>
        </div>
    </header>
    @if ($scanMessage)
        <p @class(['border-b px-4 py-2 text-sm sm:px-6', 'border-red-200 bg-red-50 text-red-800' => $scanFailed, 'border-emerald-200 bg-emerald-50 text-emerald-900' => ! $scanFailed])
           role="{{ $scanFailed ? 'alert' : 'status' }}" data-guide-scan-message>{{ $scanMessage }}</p>
    @endif

    <nav class="flex gap-1 overflow-x-auto border-b border-stone-200 bg-white px-4 sm:px-6" aria-label="Bagian panel">
        @foreach (\App\Livewire\Panduan\Updates::TABS as $t => $label)
            <button type="button" wire:click="$set('tab', '{{ $t }}')" @if ($tab === $t) aria-current="page" @endif data-guide-tab="{{ $t }}"
                    @class(['-mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap', 'border-emerald-700 text-emerald-900' => $tab === $t, 'border-transparent text-stone-500 hover:text-stone-800' => $tab !== $t])>{{ $label }}@if ($t === 'usang' && $this->stats['stale'] > 0) <span class="ml-1 rounded-full bg-amber-100 px-1.5 text-[11px] font-semibold text-amber-900" data-stale-count>{{ $this->stats['stale'] }}</span>@endif</button>
        @endforeach
    </nav>

    @if ($tab === 'pemindaian')
        @include('livewire.panduan.partials.scan-report', ['scan' => $this->scan])
    @elseif ($tab === 'riwayat')
        @include('livewire.panduan.partials.sync-runs', ['runs' => $this->runs])
    @elseif ($tab === 'usang')
        @include('livewire.panduan.partials.stale-guides', ['rows' => $this->staleDetails])
    @elseif ($tab === 'draf')
        @include('livewire.panduan.partials.draft-preview', ['p' => $this->draftPreview])
    @else
    <div class="space-y-5 px-4 py-5 sm:px-6">
        {{-- Ringkasan --}}
        @php $s = $this->stats; @endphp
        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" aria-label="Ringkasan panduan" data-guide-update-stats>
            @foreach ([
                ['Total panduan', $s['total'], null, 'total'],
                ['Otomatis', $s['auto'], 'disusun dari sistem', 'auto'],
                ['Manual', $s['manual'], 'tidak ditimpa', 'manual'],
                ['Disunting admin', $s['overridden'], 'punya suntingan', 'overridden'],
                ['Perlu ditinjau', $s['stale'], 'ditandai usang', 'stale'],
            ] as [$label, $value, $hint, $code])
                <div @class(['rounded-2xl border bg-white p-4', 'border-amber-300' => $code === 'stale' && $value > 0, 'border-stone-200' => ! ($code === 'stale' && $value > 0)]) data-stat="{{ $code }}">
                    <p class="text-xs text-stone-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $value }}</p>
                    @if ($hint)<p class="text-[11px] text-stone-500">{{ $hint }}</p>@endif
                </div>
            @endforeach
        </section>
        <p class="text-xs text-stone-500" data-guide-last-generated>
            Terakhir disusun: {{ $s['last'] ? $s['last']->translatedFormat('j M Y H:i') : 'belum pernah' }}
            @if ($run = $this->lastRun)
                · Sinkronisasi terakhir: <span @class(['font-semibold', 'text-red-700' => $run->status === 'failed', 'text-stone-700' => $run->status !== 'failed']) data-last-run-status="{{ $run->status }}">{{ \App\Models\GuideSyncJob::STATUSES[$run->status] }}</span>
                ({{ \App\Models\GuideSyncJob::TRIGGERS[$run->trigger] ?? $run->trigger }}, {{ $run->created_at?->diffForHumans() }})
                <button type="button" wire:click="$set('tab', 'riwayat')" class="font-medium text-emerald-800 hover:underline">lihat riwayat</button>
            @endif
        </p>

        @if ($result)
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status" data-guide-update-result>
                <p class="font-semibold">Panduan disusun ulang.</p>
                <p>{{ $result['created'] }} baru · {{ $result['updated'] }} diperbarui · {{ $result['unchanged'] }} tetap · {{ $result['skipped_manual'] }} manual dilewati. Perubahan tercatat di versi panduan dan jejak Audit.</p>
            </div>
        @endif

        {{-- Pratinjau & terapkan --}}
        <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="plan-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="plan-title" class="font-semibold">Periksa perubahan sistem</h2>
                    <p class="text-xs text-stone-500">Bandingkan panduan tersimpan dengan menu & halaman yang terbaca sekarang, tanpa menyimpan apa pun.</p>
                </div>
                <button type="button" wire:click="check" wire:loading.attr="disabled" wire:target="check" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-800 hover:bg-stone-50" data-guide-check>
                    <span wire:loading.remove wire:target="check">Periksa perubahan</span><span wire:loading wire:target="check">Memeriksa…</span>
                </button>
            </div>

            @if ($plan !== null)
                @php $changes = count($plan['created']) + count($plan['updated']); @endphp
                <div class="mt-4 space-y-3 text-sm" data-guide-plan>
                    @if ($changes === 0)
                        <p class="rounded-lg bg-stone-50 px-3 py-2 text-stone-700" data-guide-plan-empty>Tidak ada perubahan — semua panduan otomatis sudah sesuai sistem ({{ count($plan['unchanged']) }} tetap).</p>
                    @endif
                    @if ($plan['created'])
                        <div data-plan-created>
                            <p class="font-semibold text-emerald-800">Panduan baru ({{ count($plan['created']) }})</p>
                            <ul class="mt-1 list-disc pl-5 text-stone-700">@foreach ($plan['created'] as $k)<li>{{ $this->titleOf($k) }} <span class="text-xs text-stone-400">{{ $k }}</span></li>@endforeach</ul>
                        </div>
                    @endif
                    @if ($plan['updated'])
                        <div data-plan-updated>
                            <p class="font-semibold text-sky-800">Akan diperbarui ({{ count($plan['updated']) }})</p>
                            <ul class="mt-1 space-y-0.5 pl-5 text-stone-700">
                                @foreach ($plan['updated'] as $k => $fields)
                                    <li class="list-disc" data-plan-updated-item="{{ $k }}">{{ $this->titleOf($k) }} — <span class="text-xs text-stone-500">berubah: {{ collect($fields)->map(fn ($f) => \App\Livewire\Panduan\Updates::FIELD_LABELS[$f] ?? $f)->join(', ') }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($plan['skipped_manual'])
                        <p class="text-xs text-stone-500" data-plan-manual>{{ count($plan['skipped_manual']) }} panduan manual dilewati: {{ collect($plan['skipped_manual'])->map(fn ($k) => $this->titleOf($k))->join(', ') }}.</p>
                    @endif
                    @if ($plan['orphaned'])
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900" data-plan-orphaned>Menu untuk {{ count($plan['orphaned']) }} panduan sudah tidak ada di sistem: {{ collect($plan['orphaned'])->map(fn ($k) => $this->titleOf($k))->join(', ') }}. Panduan itu tidak dihapus otomatis.</p>
                    @endif
                    @if ($changes > 0)
                        <form wire:submit="apply" class="flex flex-wrap items-end gap-2 border-t border-stone-100 pt-3">
                            <label class="min-w-56 flex-1 text-xs text-stone-600">Catatan (opsional, tercatat di versi & audit)
                                <input type="text" wire:model="note" maxlength="200" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" placeholder="mis. menu Pengadaan menambah halaman Retur" data-guide-apply-note>
                            </label>
                            <button type="submit" wire:loading.attr="disabled" wire:target="apply" class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800" data-guide-apply>Terapkan {{ $changes }} perubahan</button>
                        </form>
                        @error('note')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
                    @endif
                </div>
            @endif
        </section>

        <div class="grid gap-5 lg:grid-cols-2">
            {{-- Panduan usang (ringkas; kelola di tab Panduan usang) --}}
            <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="stale-title" data-guide-stale-list>
                <h2 id="stale-title" class="font-semibold">Perlu ditinjau ulang</h2>
                <p class="text-xs text-stone-500">Panduan yang ditandai usang setelah ada perubahan menu atau fitur.</p>
                <ul class="mt-3 divide-y divide-stone-100 text-sm">
                    @forelse ($this->stale->take(5) as $e)
                        <li class="py-2" wire:key="stale-{{ $e->id }}" data-stale-item="{{ $e->key }}">
                            <span class="font-medium text-stone-900">{{ $e->title }}</span>
                            <p class="text-xs text-stone-500">{{ $e->stale_reason ?: 'Ada perubahan sejak panduan disusun.' }}</p>
                        </li>
                    @empty
                        <li class="py-3 text-stone-500" data-stale-empty>Tidak ada panduan yang perlu ditinjau.</li>
                    @endforelse
                </ul>
                <button type="button" wire:click="$set('tab', 'usang')" class="mt-2 text-sm font-medium text-emerald-800 hover:underline" data-open-stale-tab>Kelola panduan usang →</button>
            </section>

            {{-- Riwayat penyusunan --}}
            <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="history-title" data-guide-generate-history>
                <h2 id="history-title" class="font-semibold">Riwayat penyusunan otomatis</h2>
                <p class="text-xs text-stone-500">20 versi terakhir hasil penyusunan otomatis.</p>
                <ul class="mt-3 divide-y divide-stone-100 text-sm">
                    @forelse ($this->history as $v)
                        <li class="py-2" wire:key="hist-{{ $v->id }}">
                            <p><span class="font-medium">{{ $v->entry?->title ?? '—' }}</span> <span class="text-xs text-stone-500">versi {{ $v->version }}</span></p>
                            <p class="text-xs text-stone-500">{{ $v->created_at?->translatedFormat('j M Y H:i') }} · {{ $v->author?->name ?? 'Sistem' }}@if ($v->change_note) · {{ $v->change_note }}@endif</p>
                        </li>
                    @empty
                        <li class="py-3 text-stone-500">Belum ada riwayat.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
    @endif
</div>
