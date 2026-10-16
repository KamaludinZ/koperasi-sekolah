{{-- Panduan usang: alasan, sejak kapan, apa yang akan diubah draf terbaru, tandai ditinjau / tandai perlu ditinjau --}}
@php $fieldLabels = \App\Livewire\Panduan\Updates::FIELD_LABELS; @endphp
<div class="space-y-4 px-4 py-5 sm:px-6" data-guide-stale>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-stone-600">Panduan ditandai usang saat menu, halaman, atau izin yang dibahasnya berubah. Pembaca melihat catatan "sedang ditinjau" sampai penandanya dilepas.</p>
        @if ($rows)
            <button type="button" wire:click="markAllReviewed" wire:confirm="Lepas penanda usang dari {{ count($rows) }} panduan?" class="rounded-xl border border-stone-300 px-3 py-1.5 text-sm font-medium hover:bg-stone-50" data-mark-all-reviewed>Tandai semua sudah ditinjau</button>
        @endif
    </div>

    <ul class="space-y-2">
        @forelse ($rows as $r)
            @php $e = $r['entry']; @endphp
            <li class="rounded-2xl border border-amber-200 bg-white p-4" wire:key="usang-{{ $e->id }}" data-stale-row="{{ $e->key }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('panduan', ['menu' => $e->key]) }}" wire:navigate class="font-semibold text-stone-900 hover:underline">{{ $e->title }}</a>
                        <p class="text-sm text-amber-900">{{ $e->stale_reason ?: 'Ada perubahan sejak panduan disusun.' }}</p>
                        <p class="mt-1 text-xs text-stone-500">
                            Ditandai {{ $e->stale_at ? $e->stale_at->diffForHumans() : 'sebelumnya' }}
                            @if ($e->generated_at) · disusun {{ $e->generated_at->translatedFormat('j M Y') }}@endif
                            @if ($e->overrides_count) · {{ $e->overrides_count }} bagian disunting admin @endif
                        </p>
                        <p class="mt-1 text-xs" data-stale-changes="{{ $e->key }}">
                            @if ($r['manual'])
                                <span class="text-stone-600">Panduan manual — tinjau dan sunting isinya langsung.</span>
                            @elseif ($r['changes'])
                                <span class="text-sky-800">Draf terbaru akan mengubah: {{ collect($r['changes'])->map(fn ($f) => $fieldLabels[$f] ?? $f)->join(', ') }}.</span>
                            @else
                                <span class="text-stone-600">Isi otomatis sudah sesuai sistem — cukup tinjau suntingannya.</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if (! $r['manual'] && $r['changes'])
                            <button type="button" wire:click="$set('draftKey', '{{ $e->key }}'); $set('tab', 'draf')" class="rounded-lg border border-sky-300 px-2.5 py-1 text-xs font-medium text-sky-800 hover:bg-sky-50" data-open-draft="{{ $e->key }}">Lihat draf</button>
                        @endif
                        <button type="button" wire:click="markReviewed({{ $e->id }})" class="rounded-lg border border-stone-300 px-2.5 py-1 text-xs font-medium hover:bg-stone-50" data-mark-reviewed="{{ $e->key }}">Tandai sudah ditinjau</button>
                    </div>
                </div>
            </li>
        @empty
            <li class="rounded-2xl border border-stone-200 bg-white px-4 py-6 text-center text-sm text-stone-500" data-stale-empty>Tidak ada panduan yang perlu ditinjau.</li>
        @endforelse
    </ul>

    <form wire:submit="markStale" class="rounded-2xl border border-stone-200 bg-white p-4" data-mark-stale-form>
        <h2 class="text-sm font-semibold">Tandai panduan perlu ditinjau</h2>
        <p class="text-xs text-stone-500">Misalnya setelah prosedur koperasi berubah tanpa perubahan menu.</p>
        <div class="mt-2 flex flex-wrap items-end gap-2">
            <label class="min-w-48 text-xs text-stone-600">Panduan
                <select wire:model="staleKey" class="mt-1 w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm" data-stale-key>
                    <option value="">Pilih panduan…</option>
                    @foreach (\App\Models\GuideEntry::where('is_stale', false)->orderBy('position')->pluck('title', 'key') as $k => $title)
                        <option value="{{ $k }}">{{ $title }}</option>
                    @endforeach
                </select>
            </label>
            <label class="min-w-56 flex-1 text-xs text-stone-600">Alasan
                <input type="text" wire:model="staleReason" maxlength="200" placeholder="mis. alur retur kini wajib persetujuan pengurus" class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm" data-stale-reason>
            </label>
            <button type="submit" class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700" data-mark-stale>Tandai</button>
        </div>
        @error('staleKey')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        @error('staleReason')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
    </form>
</div>
