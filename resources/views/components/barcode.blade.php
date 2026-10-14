@props(['value', 'height' => 48, 'caption' => true])
{{-- Barcode CODE128 (SVG inline) + teks nilainya. Lebar mengikuti class wadah, mis. class="w-48". --}}
@php $svg = \App\Support\BarcodeImage::svg((string) $value, (float) $height); @endphp
<figure {{ $attributes->merge(['class' => 'inline-block']) }} data-barcode="{{ $value }}">
    @if ($svg)
        <div class="[&>svg]:block [&>svg]:h-auto [&>svg]:w-full" style="aspect-ratio: auto">{!! $svg !!}</div>
    @else
        <div class="rounded border border-dashed border-stone-300 px-2 py-3 text-center text-xs text-stone-500">Barcode tidak bisa dibuat</div>
    @endif
    @if ($caption)
        <figcaption class="mt-1 text-center font-mono text-xs tracking-widest text-stone-700">{{ $value }}</figcaption>
    @endif
</figure>
