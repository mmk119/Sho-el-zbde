@include('layouts.app', [
    'title' => 'Something broke — Sho el Zbde',
    'slot' => new \Illuminate\Support\HtmlString(
        view('errors.partials.message', [
            'heading' => 'Something broke on our side',
            'body' => 'That is our fault, not yours. Nothing you uploaded has been lost. '
                .'Try again in a moment.',
        ])->render()
    ),
])
