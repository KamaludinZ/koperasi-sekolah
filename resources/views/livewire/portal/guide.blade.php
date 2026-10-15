<div class="space-y-4">
    <div>
        <h1 class="text-xl font-semibold">Panduan Portal</h1>
        <p class="text-sm text-stone-500">Cara memakai portal anggota langkah demi langkah.</p>
    </div>

    <div class="flex gap-1.5 overflow-x-auto pb-1" role="tablist" aria-label="Topik panduan">
        @foreach ($this->entries as $key => $e)
            <button type="button" role="tab" wire:click="open('{{ $key }}')" aria-selected="{{ $currentKey === $key ? 'true' : 'false' }}" data-portal-guide-item="{{ $key }}"
                    @class(['shrink-0 rounded-full px-3 py-1.5 text-sm font-medium', 'bg-emerald-700 text-white' => $currentKey === $key, 'bg-white text-stone-700 ring-1 ring-stone-200 hover:bg-stone-50' => $currentKey !== $key])>
                {{ $e['title'] }}
            </button>
        @endforeach
    </div>

    @if ($current)
        <article class="rounded-2xl border border-stone-200 bg-white p-5" data-portal-guide="{{ $currentKey }}">
            <h2 class="text-lg font-semibold">{{ $current['title'] }}</h2>
            <p class="mt-1 text-sm text-stone-700">{{ $current['summary'] }}</p>
            <ol class="mt-4 space-y-3">
                @foreach ($current['steps'] as $i => $step)
                    <li class="flex gap-3">
                        <span class="grid size-7 shrink-0 place-items-center rounded-full bg-emerald-700 text-xs font-bold text-white" aria-hidden="true">{{ $i + 1 }}</span>
                        <div class="pt-0.5 text-sm text-stone-800">{{ $step['text'] }}
                            @if (($step['route'] ?? null) && \Illuminate\Support\Facades\Route::has($step['route']) && $step['route'] !== 'portal.checkout')
                                <a href="{{ route($step['route']) }}" wire:navigate class="ml-1 font-medium whitespace-nowrap text-emerald-800 hover:underline">Buka →</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            @if ($current['tips'])
                <div class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <ul class="list-disc space-y-0.5 pl-5">@foreach ($current['tips'] as $tip)<li>{{ $tip }}</li>@endforeach</ul>
                </div>
            @endif
        </article>
    @endif
</div>
