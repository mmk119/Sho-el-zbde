@include('layouts.app', [
    'title' => 'Not found · Sho el Zbde',
    'slot' => new \Illuminate\Support\HtmlString(
        view('errors.partials.message', [
            'heading' => "There's nothing here",
            'body' => 'That link does not match any voice note. Share links are long and '
                .'exact, so it may have been cut short somewhere along the way, or the '
                .'note may already have been deleted.',
        ])->render()
    ),
])
