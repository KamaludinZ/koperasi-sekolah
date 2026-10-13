{{-- Dampak diskon & promo pada periode laporan: potongan per sumber, transaksi berdiskon, promo/kupon teratas --}}
@php
    $dr = $this->discountReport;
    $ds = $dr['summary'];
    $maxSource = max(1, ...array_column($dr['by_source'], 'amount'));
    $top = collect([...array_map(fn ($r) => ['kind' => 'Promo', ...$r], $dr['promos']), ...array_map(fn ($r) => ['kind' => 'Kupon', ...$r], $dr['coupons'])])
        ->sortByDesc('amount')->take(5)->values();
@endphp
<section class="rounded-xl border border-stone-200 bg-white p-4" aria-labelledby="discount-report-title">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="discount-report-title" class="text-sm font-semibold">Diskon & promo</h2>
        <a href="{{ route('promo') }}" wire:navigate class="text-xs font-semibold text-emerald-800 hover:underline">Kelola promo →</a>
    </div>

    @if ($ds['total_discount'] === 0)
        <p class="mt-4 text-center text-sm text-stone-400">Tidak ada potongan harga pada periode ini.</p>
    @else
        <div class="mt-3 grid gap-4 lg:grid-cols-3">
            <dl class="space-y-2 text-sm">
                <div>
                    <dt class="text-xs text-stone-500">Total potongan</dt>
                    <dd class="text-xl font-bold text-emerald-900 tabular-nums">{{ $rp($ds['total_discount']) }} <span class="text-xs font-medium text-stone-500">· {{ str_replace('.', ',', (string) $ds['discount_rate']) }}% dari penjualan kotor</span></dd>
                </div>
                <div class="flex justify-between gap-2"><dt class="text-stone-500">Transaksi berdiskon</dt><dd class="font-medium tabular-nums">{{ $ds['discounted_transactions'] }} dari {{ $ds['transactions'] }} ({{ $ds['discounted_share'] }}%)</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-stone-500">Rata-rata belanja berdiskon</dt><dd class="font-medium tabular-nums">{{ $rp($ds['avg_discounted']) }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-stone-500">Rata-rata tanpa diskon</dt><dd class="font-medium tabular-nums">{{ $rp($ds['avg_plain']) }}</dd></div>
            </dl>

            <div>
                <p class="mb-2 text-xs text-stone-500">Potongan per sumber</p>
                <ul class="space-y-2.5">
                    @foreach ($dr['by_source'] as $key => $src)
                        <li>
                            <div class="flex justify-between gap-2 text-sm"><span>{{ $src['label'] }} <span class="text-xs text-stone-400">· {{ $src['count'] }}×</span></span><span class="font-semibold tabular-nums">{{ $rp($src['amount']) }}</span></div>
                            <div class="mt-1 h-1.5 rounded-full bg-stone-100"><div class="h-full rounded-full bg-emerald-600" style="width: {{ round($src['amount'] / $maxSource * 100) }}%"></div></div>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <p class="mb-2 text-xs text-stone-500">Promo & kupon terbanyak memotong</p>
                @if ($top->isEmpty())
                    <p class="text-sm text-stone-400">Belum ada promo atau kupon terpakai.</p>
                @else
                    <ol class="space-y-1.5 text-sm">
                        @foreach ($top as $r)
                            <li class="flex items-baseline justify-between gap-2">
                                <span class="min-w-0 truncate"><span class="mr-1 rounded bg-stone-100 px-1 text-[10px] font-semibold text-stone-600">{{ $r['kind'] }}</span>{{ $r['label'] }}</span>
                                <span class="shrink-0 tabular-nums"><span class="text-xs text-stone-400">{{ $r['uses'] }}×</span> {{ $rp($r['amount']) }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>
    @endif
</section>
