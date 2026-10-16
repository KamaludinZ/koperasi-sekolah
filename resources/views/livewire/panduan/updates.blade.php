<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex flex-wrap items-end justify-between gap-3 border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Pembaruan Panduan</h1>
            <p class="text-xs text-stone-500">Susun ulang panduan otomatis dari konfigurasi menu, halaman, dan izin peran. Panduan yang disunting manual tidak ditimpa.</p>
        </div>
        <a href="{{ route('panduan') }}" wire:navigate class="text-sm font-medium text-emerald-800 hover:underline">← Pusat Panduan</a>
    </header>

    <nav class="flex gap-1 border-b border-stone-200 bg-white px-4 sm:px-6" aria-label="Bagian panel">
        @foreach (\App\Livewire\Panduan\Updates::TABS as $t => $label)
            <button type="button" wire:click="$set('tab', '{{ $t }}')" @if ($tab === $t) aria-current="page" @endif data-guide-tab="{{ $t }}"
                    @class(['-mb-px border-b-2 px-3 py-2 text-sm font-medium', 'border-emerald-700 text-emerald-900' => $tab === $t, 'border-transparent text-stone-500 hover:text-stone-800' => $tab !== $t])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'pemindaian')
        @include('livewire.panduan.partials.scan-report', ['scan' => $this->scan])
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
            Terakhir disusun: {{ $s['last'] ? $s['last']->translatedFormat('j M Y H:i') : 'belum pernah' }} ·
            Penyusunan berjalan saat tombol di bawah ditekan atau lewat perintah <span class="font-mono">panduan:susun</span>.
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
            {{-- Panduan usang --}}
            <section class="rounded-2xl border border-stone-200 bg-white p-5" aria-labelledby="stale-title" data-guide-stale-list>
                <h2 id="stale-title" class="font-semibold">Perlu ditinjau ulang</h2>
                <p class="text-xs text-stone-500">Panduan yang ditandai usang setelah ada perubahan menu atau fitur.</p>
                <ul class="mt-3 divide-y divide-stone-100 text-sm">
                    @forelse ($this->stale as $e)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="stale-{{ $e->id }}" data-stale-item="{{ $e->key }}">
                            <div class="min-w-0">
                                <a href="{{ route('panduan', ['menu' => $e->key]) }}" wire:navigate class="font-medium text-stone-900 hover:underline">{{ $e->title }}</a>
                                <p class="text-xs text-stone-500">{{ $e->stale_reason ?: 'Ada perubahan sejak panduan disusun.' }}</p>
                            </div>
                            <button type="button" wire:click="markReviewed({{ $e->id }})" class="rounded-lg border border-stone-300 px-2.5 py-1 text-xs font-medium hover:bg-stone-50" data-mark-reviewed="{{ $e->key }}">Tandai sudah ditinjau</button>
                        </li>
                    @empty
                        <li class="py-3 text-stone-500" data-stale-empty>Tidak ada panduan yang perlu ditinjau.</li>
                    @endforelse
                </ul>
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
