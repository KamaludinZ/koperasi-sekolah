@props(['status'])
@php
    $tone = ['diajukan' => 'bg-sky-100 text-sky-800', 'disetujui' => 'bg-indigo-100 text-indigo-800', 'berjalan' => 'bg-emerald-100 text-emerald-800',
        'menunggak' => 'bg-red-100 text-red-800', 'lunas' => 'bg-stone-100 text-stone-700', 'ditolak' => 'bg-stone-200 text-stone-600',
        'dibatalkan' => 'bg-stone-200 text-stone-600'][$status] ?? 'bg-stone-100 text-stone-700';
@endphp
<span {{ $attributes->merge(['class' => "inline-block rounded-md px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {$tone}"]) }} data-loan-status="{{ $status }}">{{ \App\Models\Loan::STATUSES[$status] ?? $status }}</span>
