@include('layouts.app', [
    'title' => 'Gone — Sho el Zbde',
    'slot' => new \Illuminate\Support\HtmlString(
        view('errors.partials.message', [
            'heading' => "This one's gone",
            'body' => 'Voice notes and their digests are deleted after '
                .config('shoelzbde.retention_days').' days. This one has passed that, so '
                .'the audio is no longer here.',
        ])->render()
    ),
])
