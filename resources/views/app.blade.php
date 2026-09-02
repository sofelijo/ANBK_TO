<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'TOA') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        <script>
            (() => {
                try {
                    const storedTheme = window.localStorage.getItem('toa-theme');
                    const useDark = storedTheme === 'dark'
                        || (storedTheme === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', useDark);
                    document.documentElement.style.colorScheme = useDark ? 'dark' : 'light';
                } catch {
                    const useDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', useDark);
                    document.documentElement.style.colorScheme = useDark ? 'dark' : 'light';
                }
            })();
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
