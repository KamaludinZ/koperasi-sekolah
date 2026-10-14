@props([
    'openEvent' => 'open-camera-scanner', // event window yang membuka pemindai
    'onCode' => '',                       // ekspresi JS; variabel `code` = hasil pindai
    'single' => false,                    // tutup otomatis setelah satu kode terbaca
    'hotkey' => false,                    // F4 membuka/menutup
    'title' => 'Pindai barcode',
    'hint' => 'Arahkan kamera ke barcode.',
])
{{-- Pemindai barcode lewat kamera (webcam/HP); logika di resources/js/barcode-scanner.js. Umpan balik
     dibaca dari event `scan-feedback` ({type, text}) bila halaman mengirimkannya. --}}
<div x-data="barcodeScanner((code) => { {{ $onCode }} }, { single: @js((bool) $single) })" x-cloak
     x-on:{{ $openEvent }}.window="start()"
     @if ($hotkey) x-on:keydown.window.f4.prevent="open ? stop() : start()" @endif
     x-on:keydown.escape.window="open && stop()"
     x-on:scan-feedback.window="lastFeedback = $event.detail"
     data-camera-scanner="{{ $openEvent }}">
    <div x-show="open" x-transition.opacity x-on:click.self="stop()" class="fixed inset-0 z-[60] flex items-end justify-center bg-stone-950/60 sm:items-center" role="dialog" aria-modal="true" aria-label="{{ $title }}">
        <div class="w-full max-w-lg overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            <div class="flex items-center justify-between gap-3 border-b border-stone-200 px-4 py-3">
                <div>
                    <h2 class="font-semibold">{{ $title }}</h2>
                    <p class="text-xs text-stone-500">{{ $hint }}</p>
                </div>
                <button type="button" x-on:click="stop()" class="rounded-lg p-1.5 text-stone-400 hover:bg-stone-100 hover:text-stone-600" aria-label="Tutup pemindai">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <div class="relative aspect-[4/3] bg-stone-900" wire:ignore>
                <div x-bind:id="viewfinderId" x-ref="viewfinder" class="size-full [&_video]:size-full [&_video]:object-cover"></div>
                <p x-show="status === 'starting'" class="absolute inset-0 grid place-items-center text-sm text-stone-300">Membuka kamera…</p>
                <div x-show="status === 'error'" class="absolute inset-0 grid place-items-center p-6 text-center">
                    <div>
                        <p class="text-sm text-white" x-text="error"></p>
                        <button type="button" x-on:click="start()" class="mt-3 rounded-lg bg-white/10 px-3 py-1.5 text-sm font-medium text-white hover:bg-white/20">Coba lagi</button>
                    </div>
                </div>
            </div>
            <div class="space-y-3 px-4 py-3">
                <p x-show="lastFeedback" aria-live="polite" class="rounded-lg px-3 py-2 text-sm"
                   x-bind:class="{ 'bg-emerald-50 text-emerald-900': lastFeedback?.type === 'success', 'bg-red-50 text-red-800': lastFeedback?.type === 'error', 'bg-amber-50 text-amber-900': lastFeedback?.type === 'info' }"
                   x-text="lastFeedback?.text"></p>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <label x-show="cameras.length > 1" class="flex items-center gap-2 text-sm"><span class="text-stone-600">Kamera</span>
                        <select x-on:change="switchCamera($event.target.value)" class="rounded-lg border border-stone-300 bg-white px-2 py-1 text-sm">
                            <template x-for="c in cameras" :key="c.id"><option :value="c.id" :selected="c.id === cameraId" x-text="c.label || 'Kamera'"></option></template>
                        </select>
                    </label>
                    <p class="text-xs text-stone-500">Barcode tidak terbaca? Tutup lalu ketik kodenya.</p>
                    <button type="button" x-on:click="stop()" class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-50">Selesai</button>
                </div>
            </div>
        </div>
    </div>
</div>
