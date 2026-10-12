@php
    $input = 'w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15';
    $targets = \App\Livewire\Notifikasi\Announcements::TARGETS;
    $staffOnly = in_array($target, ['kasir', 'pengurus'], true);
@endphp
<div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-4 sm:px-6">
        <div>
            <h1 class="text-lg font-semibold">Buat Pengumuman</h1>
            <p class="text-xs text-stone-500">Sampaikan info kegiatan atau aturan baru ke anggota dan petugas.</p>
        </div>
        <a href="{{ route('notifikasi') }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:underline">← Kembali ke notifikasi</a>
    </header>

    <div class="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
        <form wire:submit="publish" class="min-w-0 space-y-4 rounded-2xl border border-stone-200 bg-white p-5" novalidate>
            <label class="block"><span class="mb-1 block text-sm font-medium">Judul</span>
                <input type="text" wire:model.live.debounce.300ms="title" maxlength="80" class="{{ $input }}" placeholder="mis. Koperasi tutup saat ujian semester">
                <span class="mt-1 flex justify-between text-[11px] text-stone-500"><span>@error('title')<span class="text-red-600">{{ $message }}</span>@enderror</span><span class="tabular-nums">{{ mb_strlen($title) }}/80</span></span>
            </label>
            <label class="block"><span class="mb-1 block text-sm font-medium">Isi pengumuman</span>
                <textarea wire:model.live.debounce.300ms="message" rows="5" maxlength="500" class="{{ $input }}" placeholder="Tulis informasi singkat dan jelas: apa, kapan, dan siapa yang perlu tahu."></textarea>
                <span class="mt-1 flex justify-between text-[11px] text-stone-500"><span>@error('message')<span class="text-red-600">{{ $message }}</span>@enderror</span><span class="tabular-nums">{{ mb_strlen($message) }}/500</span></span>
            </label>

            <fieldset>
                <legend class="mb-1.5 text-sm font-medium">Ditujukan untuk</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($targets as $value => $label)
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border border-stone-300 px-3 py-2.5 text-sm has-checked:border-emerald-600 has-checked:bg-emerald-50">
                            <input type="radio" value="{{ $value }}" wire:model.live="target" class="accent-emerald-700"> {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('target') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <label @class(['flex items-start gap-3 rounded-xl border border-stone-200 p-3', 'opacity-50' => $staffOnly])>
                    <input type="checkbox" wire:model.live="show_public" @disabled($staffOnly) class="mt-0.5 size-4 accent-emerald-700">
                    <span><span class="block text-sm font-medium">Tampilkan di beranda publik</span>
                        <span class="block text-xs text-stone-500">{{ $staffOnly ? 'Pengumuman khusus petugas tidak ditampilkan publik.' : 'Siswa & wali bisa membacanya tanpa masuk.' }}</span></span>
                </label>
                <label class="block"><span class="mb-1 block text-sm font-medium">Berlaku sampai <span class="font-normal text-stone-500">(opsional)</span></span>
                    <input type="date" wire:model.live="expires_at" min="{{ now()->toDateString() }}" class="{{ $input }}">
                    @error('expires_at') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="flex justify-end">
                <button type="submit" wire:loading.attr="disabled" wire:target="publish" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Terbitkan pengumuman</button>
            </div>
        </form>

        <aside class="h-fit space-y-4 lg:sticky lg:top-4">
            <div>
                <p class="mb-2 text-xs font-semibold tracking-wide text-stone-500 uppercase">Pratinjau</p>
                <article class="relative isolate overflow-hidden rounded-2xl bg-emerald-950 p-5 text-emerald-50">
                    <div class="pattern-madrasah absolute inset-0 -z-10 opacity-[0.08]" aria-hidden="true"></div>
                    <p class="text-xs font-semibold tracking-wide text-amber-300 uppercase">Pengumuman · untuk {{ \App\Livewire\Notifikasi\Index::TARGETS[$target] ?? $target }}</p>
                    <h2 class="mt-1 font-display text-xl font-bold break-words text-white">{{ $title ?: 'Judul pengumuman' }}</h2>
                    <p class="mt-2 text-sm break-words whitespace-pre-line text-emerald-100">{{ $message ?: 'Isi pengumuman akan tampil di sini.' }}</p>
                    <p class="mt-3 text-xs text-emerald-300">{{ auth()->user()?->name }} · {{ now()->translatedFormat('d F Y') }}@if ($expires_at) · berlaku sampai {{ \Illuminate\Support\Carbon::parse($expires_at)->translatedFormat('d F Y') }}@endif</p>
                </article>
            </div>

            <section aria-labelledby="ann-list">
                <h2 id="ann-list" class="mb-2 text-sm font-semibold">Pengumuman yang dibuat</h2>
                <ul class="divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 bg-white">
                    @forelse ($this->announcements as $a)
                        @php $expired = $a['expires_at'] && $a['expires_at'] < now()->toDateString(); @endphp
                        <li wire:key="ann-{{ $a['id'] }}" class="flex items-start gap-2 px-3 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p @class(['truncate text-sm font-medium', 'text-stone-400 line-through' => $expired])>{{ $a['title'] }}</p>
                                <p class="text-xs text-stone-500">
                                    {{ \App\Livewire\Notifikasi\Index::TARGETS[$a['target']] ?? $a['target'] }}
                                    @if ($a['show_public']) · <span class="text-emerald-700">beranda publik</span>@endif
                                    @if ($expired) · berakhir @elseif ($a['expires_at']) · s.d. {{ \Illuminate\Support\Carbon::parse($a['expires_at'])->translatedFormat('d M') }}@endif
                                </p>
                            </div>
                            <button type="button" wire:click="delete({{ $a['id'] }})" wire:confirm="Hapus pengumuman ini?" class="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50">Hapus</button>
                        </li>
                    @empty
                        <li class="px-3 py-6 text-center text-sm text-stone-500">Belum ada pengumuman.</li>
                    @endforelse
                </ul>
            </section>
        </aside>
    </div>
</div>
