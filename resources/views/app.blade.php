<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>

    <!-- Prevent Flash of Light Mode -->
    <script>
        (function() {
            var theme = null;
            try {
                theme = localStorage.getItem('theme') ?? localStorage.getItem('vueuse-color-scheme');
            } catch (e) {}

            var prefersDark =
                window.matchMedia &&
                window.matchMedia('(prefers-color-scheme: dark)').matches;

            // Explicit 'dark' wins, explicit 'light' wins, anything else follows the OS
            var isDark = theme === 'dark' || (theme !== 'light' && prefersDark);

            var root = document.documentElement;
            root.classList.toggle('dark', isDark);
            root.style.colorScheme = isDark ? 'dark' : 'light';
        })();
    </script>
    @vite('resources/css/app.css')
</head>

<body class="bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-100 overflow-hidden">
    <div id="app">
        <div class="app-loader-overlay" role="status" aria-label="Loading">
            <div class="app-spinner"></div>
            <span class="sr-only">Loading…</span>
        </div>
    </div>
    @vite('resources/js/app.ts')
</body>

</html>
