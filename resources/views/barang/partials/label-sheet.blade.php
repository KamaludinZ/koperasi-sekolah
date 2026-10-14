{{-- Satu lembar label. Butuh: $f (LabelFormats::get), $page (list label), $showName, $showPrice. Ukuran dalam mm. --}}
<div class="label-sheet relative overflow-hidden bg-white text-black"
     style="width: {{ $f['page_w'] }}mm; height: {{ $f['page_h'] }}mm; padding: {{ $f['top'] }}mm 0 0 {{ $f['left'] }}mm;">
    <div style="display: grid; grid-template-columns: repeat({{ $f['cols'] }}, {{ $f['label_w'] }}mm); grid-auto-rows: {{ $f['label_h'] }}mm;">
        @foreach ($page as $l)
            <div class="flex flex-col items-center justify-center overflow-hidden text-center leading-tight" style="padding: 1.5mm 2mm;" data-label="{{ $l['code'] }}">
                @if ($showName)<p class="line-clamp-2 w-full font-medium" style="font-size: 2.6mm;">{{ $l['name'] }}</p>@endif
                @php $svg = \App\Support\BarcodeImage::svg($l['barcode'], 40); @endphp
                @if ($svg)
                    <div class="w-[86%] [&>svg]:block [&>svg]:w-full" style="height: {{ $f['barcode_h'] }}mm; margin-top: .8mm;"><div class="h-full [&>svg]:h-full [&>svg]:w-full">{!! $svg !!}</div></div>
                @endif
                <p class="font-mono tracking-wider" style="font-size: 2.4mm;">{{ $l['barcode'] }}</p>
                @if ($showPrice)<p class="font-bold" style="font-size: 3.2mm;">{{ \App\Support\Rupiah::format($l['price']) }}</p>@endif
            </div>
        @endforeach
    </div>
</div>
