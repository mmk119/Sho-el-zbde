{{--
    Shared body for the error pages. Kept deliberately plain: an error page is
    not the place to explain the product, and a wrong link should not look like
    a crash.
--}}
<h1 class="font-serif text-[2rem] leading-tight tracking-tight text-sand-900 dark:text-sand-50">{{ $heading }}</h1>

<p class="mt-5 max-w-md leading-relaxed text-sand-500 dark:text-sand-400">{{ $body }}</p>

<a href="{{ route('upload') }}" class="btn-quiet mt-8">Start a new one</a>
