<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f5f4" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        (function () {
            var match = document.cookie.match(/(?:^|; )theme=([^;]*)/);
            var theme = match ? decodeURIComponent(match[1]) : '';
            if (theme !== 'light' && theme !== 'dark' && theme !== 'system') {
                try { theme = localStorage.getItem('makerly.theme') || ''; } catch (e) { theme = ''; }
            }
            if (theme !== 'light' && theme !== 'dark' && theme !== 'system') theme = 'system';
            var dark = theme === 'dark' || (theme !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (dark) document.documentElement.classList.add('dark');
            document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @include('partials.fonts')
    @inertiaHead
</head>
<body class="min-h-screen bg-canvas text-ink antialiased">
    @inertia
</body>
</html>
