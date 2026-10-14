@props(['loans', 'empty' => 'Tidak ada pinjaman.'])
{{-- Daftar kartu pinjaman anggota; menampilkan pesan kosong bila hasil saringan tidak berisi apa pun. --}}
@if ($loans === [])
    <p class="rounded-2xl border border-dashed border-stone-300 bg-white px-4 py-8 text-center text-sm text-stone-500" data-empty="daftar-pinjaman">{{ $empty }}</p>
@else
    <ul {{ $attributes->merge(['class' => 'space-y-3', 'aria-label' => 'Daftar pinjaman']) }}>
        @foreach ($loans as $loan)
            <x-portal.loan-card :loan="$loan" wire:key="my-loan-{{ $loan['id'] }}" />
        @endforeach
    </ul>
@endif
