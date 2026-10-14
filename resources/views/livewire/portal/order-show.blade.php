@php
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $o = $this->order;
    $steps = \App\Livewire\Portal\OrderShow::STEPS;
    $current = array_search($o->status, $steps, true);
    $active = ! in_array($o->status, ['selesai', 'dibatalkan'], true);
@endphp
<div class="space-y-5" @if ($active) wire:poll.30s.visible @endif>
    <div>
        <a href="{{ route('portal.pesanan') }}" wire:navigate class="text-sm text-stone-500 hover:text-emerald-800">← Semua pesanan</a>
        <div class="mt-1 flex flex-wrap items-center gap-2">
            <h1 class="font-mono text-lg font-semibold">{{ $o->code }}</h1>
            <x-portal.order-status :status="$o->status" />
        </div>
        <p class="text-sm text-stone-500">{{ $o->created_at->translatedFormat('l, d F Y H:i') }}</p>
    </div>

    @if ($d = $this->deduction)
        <section @class(['rounded-2xl border p-4 text-sm', 'border-emerald-200 bg-emerald-50' => ! $d['refund'], 'border-stone-200 bg-white' => $d['refund']]) data-deduction-receipt aria-labelledby="ded-title">
            <div class="flex items-center gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-emerald-600 text-white" aria-hidden="true">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="m5 12 5 5L20 7"/></svg></span>
                <div>
                    <h2 id="ded-title" class="font-semibold text-emerald-900">Pembayaran berhasil</h2>
                    <p class="text-xs text-emerald-800">Dipotong dari {{ $d['source'] }} · {{ $d['at']->translatedFormat('d M Y H:i') }} · tanpa persetujuan pengurus</p>
                </div>
            </div>
            <dl class="mt-3 space-y-1">
                <div class="flex justify-between"><dt class="text-stone-600">Saldo sebelum</dt><dd class="tabular-nums">{{ $rp($d['before']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-stone-600">Dipotong</dt><dd class="tabular-nums">−{{ $rp($d['amount']) }}</dd></div>
                <div class="flex justify-between border-t border-emerald-200 pt-1 font-semibold"><dt>Saldo sesudah</dt><dd class="tabular-nums" data-saldo-after>{{ $rp($d['after']) }}</dd></div>
                @if ($d['refund'])
                    <div class="flex justify-between text-emerald-800"><dt>Dikembalikan {{ $d['refund']['at']->translatedFormat('d M H:i') }}</dt><dd class="tabular-nums">+{{ $rp($d['refund']['amount']) }}</dd></div>
                @endif
            </dl>
        </section>
    @endif

    @if ($o->status === 'dibatalkan')
        <p class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">Pesanan dibatalkan{{ $o->cancel_reason ? ': '.$o->cancel_reason : '' }}.@if ($o->payment_status === 'dikembalikan') Saldo sudah dikembalikan.@endif</p>
    @else
        <ol class="grid grid-cols-4 gap-2" aria-label="Langkah pesanan">
            @foreach ($steps as $i => $step)
                <li class="text-center">
                    <span @class(['mx-auto block h-1.5 rounded-full', 'bg-emerald-600' => $current !== false && $i <= $current, 'bg-stone-200' => $current === false || $i > $current])></span>
                    <span @class(['mt-1 block text-[11px] font-medium', 'text-emerald-800' => $current !== false && $i <= $current, 'text-stone-400' => $current === false || $i > $current])>
                        {{ $step === 'siap' ? ($o->fulfillment === 'antar' ? 'Siap diantar' : 'Siap diambil') : \App\Models\Order::STATUSES[$step] }}</span>
                </li>
            @endforeach
        </ol>
        <p class="text-sm text-stone-600">
            @switch($o->status)
                @case('menunggu') Pesanan diterima dan menunggu disiapkan petugas. @break
                @case('diproses') Petugas sedang menyiapkan barang Anda. @break
                @case('siap') {{ $o->fulfillment === 'antar' ? 'Barang segera diantar ke '.$o->delivery_to.'.' : 'Barang sudah siap — ambil di koperasi.' }} @break
                @case('selesai') Pesanan selesai. Terima kasih! @break
            @endswitch
        </p>
    @endif

    <section class="rounded-2xl border border-stone-200 bg-white text-sm" aria-labelledby="od-items">
        <h2 id="od-items" class="px-4 pt-4 font-semibold">Barang</h2>
        <ul class="mt-2 divide-y divide-stone-100 px-4">
            @foreach ($o->items as $i)
                <li class="flex justify-between gap-3 py-2"><span>{{ $i->product_name }} <span class="block text-xs text-stone-500">{{ $i->quantity }} × {{ $rp((int) $i->price) }}</span></span><span class="tabular-nums">{{ $rp((int) $i->subtotal) }}</span></li>
            @endforeach
        </ul>
        <dl class="space-y-1 border-t border-stone-200 px-4 py-3">
            <div class="flex justify-between text-base font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ $rp((int) $o->total) }}</dd></div>
            <div class="flex justify-between"><dt class="text-stone-500">Pembayaran</dt><dd>{{ \App\Models\Order::PAYMENTS[$o->payment_method] }}{{ $o->savingType ? ' ('.$o->savingType->name.')' : '' }} · {{ \App\Models\Order::PAYMENT_STATUSES[$o->payment_status] }}</dd></div>
            <div class="flex justify-between"><dt class="text-stone-500">Pengambilan</dt><dd>{{ $o->fulfillment === 'antar' ? 'Diantar ke '.$o->delivery_to : 'Ambil sendiri di koperasi' }}</dd></div>
            @if ($o->note)<div class="flex justify-between"><dt class="text-stone-500">Catatan</dt><dd>{{ $o->note }}</dd></div>@endif
        </dl>
    </section>

    <section class="rounded-2xl border border-stone-200 bg-white px-4 py-4 text-sm" aria-labelledby="od-log">
        <h2 id="od-log" class="font-semibold">Riwayat status</h2>
        <ol class="mt-2 space-y-2 border-l border-stone-200 pl-4">
            @foreach ($o->statusLogs as $log)
                <li wire:key="log-{{ $log->id }}">
                    <p><x-portal.order-status :status="$log->to_status" /> <span class="text-xs text-stone-500">{{ $log->created_at->translatedFormat('d M Y H:i') }} · {{ $log->actor_name }}</span></p>
                    @if ($log->note)<p class="mt-0.5 text-stone-600">{{ $log->note }}</p>@endif
                </li>
            @endforeach
        </ol>
    </section>

    @if ($o->status === 'menunggu')
        @if ($cancelling)
            <div class="space-y-2 rounded-2xl border border-red-200 bg-red-50 p-4">
                <label class="block text-sm"><span class="mb-1 block font-medium text-red-900">Alasan pembatalan</span>
                    <input type="text" wire:model="reason" maxlength="200" class="w-full rounded-lg border border-red-200 bg-white px-3 py-2 text-sm"></label>
                @error('reason') <p class="text-xs text-red-700">{{ $message }}</p> @enderror
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="$set('cancelling', false)" class="rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-white">Tidak jadi</button>
                    <button type="button" wire:click="cancel" class="rounded-lg bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800">Batalkan pesanan</button>
                </div>
            </div>
        @else
            <button type="button" wire:click="$set('cancelling', true)" class="w-full text-center text-sm font-semibold text-red-700 hover:underline">Batalkan pesanan…</button>
        @endif
    @endif
</div>
