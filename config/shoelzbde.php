<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | Both are checked against the ORIGINAL upload, before NormalizeAudio is
    | dispatched. Silence trimming shortens audio, so validating after
    | normalization would let over-length notes through.
    |
    */

    'max_upload_mb' => (int) env('MAX_UPLOAD_MB', 100),
    'max_duration_seconds' => (int) env('MAX_DURATION_SECONDS', 1200),

    /*
    |--------------------------------------------------------------------------
    | Retention and rate limiting
    |--------------------------------------------------------------------------
    */

    'retention_days' => (int) env('RETENTION_DAYS', 30),
    'anon_uploads_per_hour' => (int) env('ANON_UPLOADS_PER_HOUR', 5),

    /*
    |--------------------------------------------------------------------------
    | Progress polling
    |--------------------------------------------------------------------------
    */

    'poll_interval_seconds' => (int) env('POLL_INTERVAL_SECONDS', 3),

    /*
    |--------------------------------------------------------------------------
    | Audio pipeline
    |--------------------------------------------------------------------------
    */

    'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', 'ffprobe'),
    'target_sample_rate' => (int) env('AUDIO_TARGET_SAMPLE_RATE', 16000),
    'target_channels' => (int) env('AUDIO_TARGET_CHANNELS', 1),

    /*
    |--------------------------------------------------------------------------
    | Accepted upload types
    |--------------------------------------------------------------------------
    */

    'accepted_mimes' => [
        'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/ogg',
        'audio/opus', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/flac',
        'audio/3gpp', 'audio/amr', 'video/mp4', 'video/webm',
    ],

];
