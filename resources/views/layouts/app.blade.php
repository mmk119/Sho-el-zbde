<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Private by design: a share link should never end up in a search index. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f5f5f4" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0c0a09" media="(prefers-color-scheme: dark)">

    <title>{{ $title ?? 'Sho el Zbde' }}</title>

    {{-- Runs before first paint so a dark-mode user never sees a white flash. --}}
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('zbde-theme');
                var system = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (saved === 'dark' || (saved === null && system)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    <div class="mx-auto flex min-h-screen w-full max-w-2xl flex-col px-5 py-6 sm:px-8 sm:py-12">

        <header class="mb-10 flex items-center justify-between gap-4 sm:mb-14">
            <a href="{{ route('upload') }}" wire:navigate class="flex items-baseline gap-2.5">
                <span class="font-serif text-xl tracking-tight text-stone-900 dark:text-stone-100">
                    Sho el Zbde<span class="text-accent-500 dark:text-accent-400">?</span>
                </span>
                <span class="hidden text-sm text-stone-400 sm:inline dark:text-stone-500">what's the gist</span>
            </a>

            <button
                type="button"
                x-data
                @click="
                    document.documentElement.classList.toggle('dark');
                    localStorage.setItem('zbde-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
                "
                class="-m-2 rounded-lg p-2 text-stone-400 transition-colors hover:text-accent-600 dark:text-stone-500 dark:hover:text-accent-300"
                aria-label="Toggle dark mode"
            >
                <svg class="h-[18px] w-[18px] dark:hidden" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 15.91A9 9 0 1 1 8.09 2.25a7.5 7.5 0 0 0 13.66 13.66Z"/>
                </svg>
                <svg class="hidden h-[18px] w-[18px] dark:block" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-3.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/>
                </svg>
            </button>
        </header>

        <main class="flex-1">
            {{ $slot }}
        </main>

        <footer class="mt-16 border-t border-stone-200 pt-6 dark:border-stone-800/80">
            <p class="hint">
                Voice notes are deleted after {{ config('shoelzbde.retention_days') }} days.
                Anyone with the link can read the digest, so share it carefully.
            </p>
        </footer>
    </div>
</body>
</html>
