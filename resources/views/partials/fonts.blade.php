@php
    $hotFile = public_path('hot');
    $manifestPath = is_file($hotFile)
        ? public_path('fonts-manifest.dev.json')
        : public_path('build/fonts-manifest.json');
    $fontManifest = is_file($manifestPath)
        ? json_decode(file_get_contents($manifestPath), true)
        : null;
    $fontStyle = is_array($fontManifest) ? ($fontManifest['style'] ?? null) : null;
@endphp
@if (is_array($fontStyle))
    @foreach ($fontManifest['preloads'] ?? [] as $preload)
        <link rel="preload" as="font" type="{{ $preload['type'] ?? 'font/woff2' }}" href="{{ $preload['url'] ?? asset('build/'.$preload['file']) }}" crossorigin="anonymous">
    @endforeach
    @if (isset($fontStyle['inline']))
        <style>{!! $fontStyle['inline'] !!}</style>
    @elseif (isset($fontStyle['file']))
        <link rel="stylesheet" href="{{ asset('build/'.$fontStyle['file']) }}">
    @endif
@endif
