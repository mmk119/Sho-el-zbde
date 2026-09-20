@include('layouts.app', [
    'title' => 'Back shortly · Sho el Zbde',
    'slot' => new \Illuminate\Support\HtmlString(
        view('errors.partials.message', [
            'heading' => 'Back in a minute',
            'body' => 'Sho el Zbde is briefly down for maintenance. Your notes are fine.',
        ])->render()
    ),
])
