{{-- Hasil pemindaian menu, halaman & izin (bahan panduan otomatis) --}}
@php
    $roleLabel = fn ($r) => \App\Models\Role::LABELS[$r] ?? $r;
    $t = $scan['totals'];
@endphp
<div class="space-y-5 px-4 py-5 sm:px-6" data-guide-scan>
    <section class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6" aria-label="Ringkasan pemindaian">
        @foreach ([['Menu', $t['menus'], 'menus'], ['Halaman terbaca', $t['pages'], 'pages'], ['Khusus peran tertentu', $t['role_pages'], 'role_pages'],
                   ['Berizin aksi', $t['gated_pages'], 'gated_pages'], ['Halaman berparameter', $t['skipped'], 'skipped'], ['Belum disebut panduan', $t['uncovered'], 'uncovered']] as [$label, $value, $code])
            <div @class(['rounded-2xl border bg-white p-4', 'border-amber-300' => $code === 'uncovered' && $value > 0, 'border-stone-200' => ! ($code === 'uncovered' && $value > 0)]) data-scan-total="{{ $code }}">
                <p class="text-xs text-stone-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $value }}</p>
            </div>
        @endforeach
    </section>
    <p class="text-xs text-stone-500">Dibaca langsung dari daftar menu, rute (penjaga menu:/peran:/can:) dan izin peran saat ini. Halaman berparameter (mis. detail satu data) tidak dijadikan langkah panduan.</p>

    @if ($scan['warnings'])
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" aria-labelledby="scan-warn" data-scan-warnings>
            <h2 id="scan-warn" class="font-semibold">Perlu perhatian ({{ count($scan['warnings']) }})</h2>
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                @foreach ($scan['warnings'] as $w)<li data-scan-warning="{{ $w['menu'] }}">{{ $w['text'] }}</li>@endforeach
            </ul>
        </section>
    @endif

    {{-- Matriks peran × menu --}}
    <section class="overflow-x-auto rounded-2xl border border-stone-200 bg-white" aria-labelledby="scan-matrix">
        <h2 id="scan-matrix" class="px-5 pt-4 font-semibold">Peran × menu</h2>
        <table class="mt-2 w-full min-w-[32rem] text-sm" data-scan-matrix>
            <thead class="text-left text-xs text-stone-500">
                <tr><th class="px-5 py-2 font-medium">Menu</th>@foreach ($scan['roles'] as $r)<th class="px-3 py-2 text-center font-medium">{{ $roleLabel($r) }}</th>@endforeach</tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach ($scan['menus'] as $key => $m)
                    <tr wire:key="mx-{{ $key }}">
                        <th scope="row" class="px-5 py-2 text-left font-medium">{{ $m['label'] }} <span class="text-xs font-normal text-stone-400">{{ $m['group'] }}</span></th>
                        @foreach ($scan['roles'] as $r)
                            <td class="px-3 py-2 text-center" data-scan-cell="{{ $key }}:{{ $r }}">
                                @if (in_array($r, $m['roles'], true))<span class="text-emerald-700" aria-label="ya">✓</span>@else<span class="text-stone-300" aria-label="tidak">—</span>@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Menu → halaman --}}
    <section class="space-y-2" aria-labelledby="scan-pages">
        <h2 id="scan-pages" class="font-semibold">Halaman per menu</h2>
        @foreach ($scan['menus'] as $key => $m)
            <details class="rounded-2xl border border-stone-200 bg-white" wire:key="sp-{{ $key }}" data-scan-menu="{{ $key }}">
                <summary class="flex cursor-pointer flex-wrap items-center gap-2 px-5 py-3 text-sm">
                    <span class="font-semibold">{{ $m['label'] }}</span>
                    <span class="text-xs text-stone-500">{{ count($m['pages']) }} halaman</span>
                    <span class="text-xs text-stone-500">· panduan: {{ $m['guide'] ?? 'belum ada' }}</span>
                    @if ($m['uncovered'])<span class="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-900" data-scan-uncovered="{{ $key }}">{{ count($m['uncovered']) }} belum disebut</span>@endif
                </summary>
                <ul class="divide-y divide-stone-100 border-t border-stone-100 text-sm">
                    @foreach ($m['pages'] as $p)
                        @php $missing = in_array($p['name'], array_column($m['uncovered'], 'name'), true); @endphp
                        <li class="flex flex-wrap items-center gap-2 px-5 py-2" data-scan-page="{{ $p['name'] }}">
                            <span class="font-medium">{{ $p['title'] }}</span>
                            <span class="font-mono text-xs text-stone-500">{{ $p['uri'] }}</span>
                            @foreach (\App\Http\Middleware\EnsureRole::parse($p['roles'])['roles'] as $r)
                                <span class="rounded bg-sky-50 px-1.5 py-0.5 text-[11px] font-semibold text-sky-800 ring-1 ring-sky-200">khusus {{ $roleLabel($r) }}</span>
                            @endforeach
                            @if ($p['ability'])<span class="rounded bg-violet-50 px-1.5 py-0.5 text-[11px] font-semibold text-violet-800 ring-1 ring-violet-200">izin {{ $p['ability'] }}</span>@endif
                            @if ($missing)<span class="text-[11px] font-semibold text-amber-800">belum disebut di panduan</span>@endif
                        </li>
                    @endforeach
                    @if ($m['skipped_pages'])
                        <li class="px-5 py-2 text-xs text-stone-500">+ {{ $m['skipped_pages'] }} halaman berparameter tidak dijadikan langkah.</li>
                    @endif
                </ul>
            </details>
        @endforeach
    </section>
</div>
