{{--
    Notifikasi toast global. Dipicu event browser `toast` {type: success|error|info, message}
    (dari Livewire: $this->dispatch('toast', ...)) atau window.dispatchEvent(new CustomEvent('toast', {detail})).
    Berhasil hilang sendiri setelah 4 detik; gagal bertahan 8 detik atau sampai ditutup.
--}}
<div x-data="{
        items: [],
        push(detail) {
            const d = Array.isArray(detail) ? detail[0] : detail;
            const id = Date.now() + Math.random();
            this.items.push({ id, type: d.type || 'info', message: d.message });
            setTimeout(() => this.remove(id), d.type === 'error' ? 8000 : 4000);
        },
        remove(id) { this.items = this.items.filter(i => i.id !== id) },
     }"
     x-on:toast.window="push($event.detail)"
     x-on:focus-first-error.window="$nextTick(() => setTimeout(() => {
        const el = document.querySelector('.text-red-600')?.closest('label, li, section, div')?.querySelector('input, textarea, select');
        el?.scrollIntoView({ behavior: 'smooth', block: 'center' }); el?.focus({ preventScroll: true });
     }, 50))"
     class="pointer-events-none fixed inset-x-0 top-3 z-[60] flex flex-col items-center gap-2 px-4 sm:top-auto sm:right-4 sm:bottom-4 sm:left-auto sm:items-end"
     aria-live="polite" role="status">
    <template x-for="t in items" :key="t.id">
        <div x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-2 opacity-0"
             x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="opacity-0"
             class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl px-4 py-3 text-sm shadow-lg ring-1"
             :class="{
                'bg-emerald-900 text-white ring-emerald-800': t.type === 'success',
                'bg-red-700 text-white ring-red-800': t.type === 'error',
                'bg-stone-900 text-white ring-stone-800': t.type === 'info',
             }"
             :role="t.type === 'error' ? 'alert' : 'status'">
            <svg x-show="t.type === 'success'" class="mt-0.5 size-4 shrink-0 text-amber-300" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 13 4 4L19 7"/></svg>
            <svg x-show="t.type !== 'success'" class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>
            <p class="flex-1" x-text="t.message"></p>
            <button type="button" x-on:click="remove(t.id)" class="-mr-1 shrink-0 opacity-70 hover:opacity-100" aria-label="Tutup notifikasi">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
    </template>
</div>
