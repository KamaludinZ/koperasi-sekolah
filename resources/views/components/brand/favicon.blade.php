{{--
    Ikon tab peramban & ikon layar utama (ponsel): logo koperasi yang diunggah di Pengaturan → Profil,
    atau lambang bawaan bila belum ada logo. Dipasang di <head> setiap tata letak.
--}}
@php
    $logo = \App\Support\MockData::profile()['logo'] ?? null;
    $type = match (strtolower(pathinfo((string) parse_url((string) $logo, PHP_URL_PATH), PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', default => 'image/png',
    };
@endphp
@if ($logo)
    <link rel="icon" type="{{ $type }}" href="{{ $logo }}">
    <link rel="apple-touch-icon" href="{{ $logo }}">
@else
    <link rel="icon" type="image/svg+xml" href="{{ route('favicon') }}">
@endif
