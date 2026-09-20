{{--
    Shared body for the error pages. Kept deliberately plain: an error page is
    not the place to explain the product, and a wrong link should not look like
    a crash.
--}}
<h1 class="font-serif text-3xl tracking-tight text-stone-900 dark:text-stone-50">{{ $heading }}</h1>

<p class="mt-4 max-w-md leading-relaxed text-stone-600 dark:text-stone-400">{{ $body }}</p>

<a href="{{ route('upload') }}" class="btn-quiet mt-8">Start a new one</a>
