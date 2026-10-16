<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold">Panduan Portal</h1>
            <p class="text-sm text-stone-500">Cara memakai portal anggota langkah demi langkah.</p>
        </div>
        @if ($from !== '')
            <a href="{{ route($from) }}" wire:navigate class="text-sm font-medium text-emerald-800 hover:underline print:hidden" data-portal-guide-back>← Kembali ke halaman sebelumnya</a>
        @endif
    </div>

    <div class="flex gap-1.5 overflow-x-auto pb-1 print:hidden" role="tablist" aria-label="Topik panduan">
        @foreach ($this->entries as $key => $e)
            <button type="button" role="tab" wire:click="open('{{ $key }}')" aria-selected="{{ $currentKey === $key ? 'true' : 'false' }}" data-portal-guide-item="{{ $key }}"
                    @class(['shrink-0 rounded-full px-3 py-1.5 text-sm font-medium', 'bg-emerald-700 text-white' => $currentKey === $key, 'bg-white text-stone-700 ring-1 ring-stone-200 hover:bg-stone-50' => $currentKey !== $key])>
                {{ $e['title'] }}
            </button>
        @endforeach
    </div>

    @if ($current)
        @php $total = count($current['steps']); @endphp
        <article class="rounded-2xl border border-stone-200 bg-white p-5" role="tabpanel" data-portal-guide="{{ $currentKey }}">
            @if ($from !== '' && \App\Support\GuideCatalog::portalTopicFor($from) === $currentKey)
                <p class="mb-1 text-xs font-semibold tracking-wider text-emerald-700 uppercase" data-portal-guide-context>Panduan untuk halaman yang Anda buka</p>
            @endif
            <h2 class="text-lg font-semibold">{{ $current['title'] }}</h2>
            <p class="mt-1 text-sm text-stone-700">{{ $current['summary'] }}</p>

            <div wire:key="portal-steps-{{ $currentKey }}" x-data="guideProgress(@js('portal-panduan:'.$memberId.':'.$currentKey), {{ $total }})" data-portal-guide-progress>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <h3 class="text-sm font-semibold text-stone-900">Langkah-langkah</h3>
                    <span class="text-xs text-stone-500 tabular-nums" aria-live="polite"><span x-text="done.length">0</span> dari {{ $total }} langkah</span>
                    <div class="h-1.5 min-w-20 flex-1 overflow-hidden rounded-full bg-stone-100 print:hidden" aria-hidden="true">
                        <div class="h-full rounded-full bg-emerald-600 transition-all" x-bind:style="`width: ${Math.round(done.length / {{ max($total, 1) }} * 100)}%`"></div>
                    </div>
                    <button type="button" x-show="done.length" x-cloak x-on:click="reset()" class="text-xs font-medium text-stone-500 hover:text-stone-800 print:hidden">Ulangi</button>
                </div>
                <ol class="mt-3 space-y-3" data-portal-guide-steps>
                    @foreach ($current['steps'] as $i => $step)
                        <li class="flex gap-3" x-bind:class="has({{ $i }}) && 'opacity-60'">
                            <button type="button" x-on:click="toggle({{ $i }})" x-bind:aria-pressed="has({{ $i }})" aria-label="Tandai langkah {{ $i + 1 }} selesai" data-portal-step-toggle="{{ $i }}"
                                    class="grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold text-white transition"
                                    x-bind:class="has({{ $i }}) ? 'bg-stone-400' : 'bg-emerald-700 hover:bg-emerald-800'">
                                <span x-show="! has({{ $i }})">{{ $i + 1 }}</span><span x-show="has({{ $i }})" x-cloak aria-hidden="true">✓</span>
                            </button>
                            <div class="min-w-0 pt-0.5 text-sm text-stone-800" x-bind:class="has({{ $i }}) && 'line-through decoration-stone-300'">{{ $step['text'] }}
                                @if ($link = $this->stepLink($step['route'] ?? null))
                                    <a href="{{ $link }}" wire:navigate class="ml-1 font-medium whitespace-nowrap text-emerald-800 hover:underline print:hidden">Buka →</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>

            @if ($current['tips'])
                <div class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900" data-portal-guide-tips>
                    <p class="font-semibold">Tips</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5">@foreach ($current['tips'] as $tip)<li>{{ $tip }}</li>@endforeach</ul>
                </div>
            @endif

            <nav class="mt-5 flex justify-between gap-3 border-t border-stone-100 pt-4 text-sm print:hidden" aria-label="Topik lain">
                @if ($prev)<button type="button" wire:click="open('{{ $prev }}')" class="font-medium text-stone-600 hover:text-stone-900" data-portal-guide-prev="{{ $prev }}">← {{ $this->entries[$prev]['title'] }}</button>@else<span></span>@endif
                @if ($next)<button type="button" wire:click="open('{{ $next }}')" class="text-right font-medium text-stone-600 hover:text-stone-900" data-portal-guide-next="{{ $next }}">{{ $this->entries[$next]['title'] }} →</button>@endif
            </nav>
        </article>
    @endif
</div>
