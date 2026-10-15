@props(['totals'])
{{-- Kartu pinjaman di beranda portal: merah menonjol bila ada tunggakan, ringkas bila berjalan lancar. $totals = MemberLoanService::outstanding()['totals']. --}}
@php $rp = fn ($n) => \App\Support\Rupiah::format($n); $t = $totals; @endphp
@if ($t['overdue_loans'] > 0)
    <a href="{{ route('portal.pinjaman') }}" wire:navigate role="alert" class="flex items-start gap-3 rounded-2xl border-2 border-red-300 bg-red-50 px-4 py-3 text-red-900 hover:border-red-500" data-home-loan="menunggak">
        <svg class="mt-0.5 size-5 shrink-0 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2 font-semibold">Pinjaman menunggak <span class="rounded-full bg-red-600 px-2 py-0.5 text-[11px] font-semibold text-white">{{ $t['overdue_loans'] }}</span></span>
            <span class="block text-sm">Tunggakan <span class="font-semibold tabular-nums">{{ $rp($t['late_amount']) }}</span>{{ ($t['next']['late'] ?? false) ? ' · terlambat '.$t['next']['days_late'].' hari' : '' }}. Segera bayar atau hubungi pengurus.</span>
        </span>
        <span aria-hidden="true" class="text-red-400">→</span>
    </a>
@elseif ($t['active'] > 0)
    <a href="{{ route('portal.pinjaman') }}" wire:navigate class="flex items-center justify-between gap-3 rounded-2xl border border-stone-200 bg-white px-4 py-3 hover:border-emerald-600" data-home-loan="berjalan">
        <span class="min-w-0">
            <span class="block font-medium">Pinjaman · sisa <span class="tabular-nums">{{ $rp($t['outstanding']) }}</span></span>
            @if ($t['next'])
                <span class="text-sm text-stone-500">Angsuran berikutnya {{ $rp($t['next']['remaining']) }} · @tanggal($t['next']['due_date'])</span>
            @endif
        </span>
        <span aria-hidden="true" class="text-stone-400">→</span>
    </a>
@endif
