<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        {{-- nonce wajib: CSP hanya mengizinkan script/style inline yang membawa nonce request ini (SEC-063) --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Latar html sebelum app.css dimuat (anti-kedip). Memakai token agar ikut warna brand kustom
             (BrandTheme mendefinisikan --background); nilai cadangan = latar desain app.css. --}}
        <style nonce="{{ Vite::cspNonce() }}">
            html {
                background-color: var(--background, #f3f6f2);
            }

            html.dark {
                background-color: var(--background, #121814);
            }
        </style>

        {{-- Nama aplikasi dari Pengaturan; dibaca app.tsx untuk judul tab (T-008). --}}
        <meta name="application-name" content="{{ $brand['title'] }}">

        @if ($brand['favicon'])
            <link rel="icon" href="{{ $brand['favicon'] }}">
        @else
            <link rel="icon" href="/favicon.ico" sizes="any">
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @endif

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])

        {{-- Warna utama kustom (BR-021). Hanya ada bila admin mengganti warna; selector html:root /
             html.dark lebih spesifik dari :root / .dark di app.css. Dicetak dengan {{ }}: isinya hanya
             hex & tanda CSS (BrandTheme::css), tanpa < > & " '. --}}
        @if ($brand['css'])
            <style nonce="{{ Vite::cspNonce() }}">{{ $brand['css'] }}</style>
        @endif

        <x-inertia::head>
            <title>{{ $brand['title'] }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
