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

    /*
    |--------------------------------------------------------------------------
    | Normalization
    |--------------------------------------------------------------------------
    |
    | The same filter chain transcribe-test.php used in Phase 0. Keeping these
    | identical is what makes the Phase 0 measurements mean anything.
    |
    */

    'normalization' => [
        'silence_duration' => env('AUDIO_SILENCE_DURATION', '1.5'),
        'silence_threshold' => env('AUDIO_SILENCE_THRESHOLD', '-40dB'),
        'timeout_seconds' => (int) env('AUDIO_NORMALIZE_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transcription
    |--------------------------------------------------------------------------
    |
    | The prompt is NOT optional. Phase 0 measured the unprompted path
    | hallucinating a phantom opening phrase and leaking non-Arabic characters
    | into Arabic text. The default below seeds Lebanese dialect markers plus
    | the app name; the upload form may append names and places, but nothing may
    | produce an empty prompt.
    |
    */

    'transcription' => [
        'driver' => env('TRANSCRIPTION_DRIVER', 'openai'),
        'model' => env('TRANSCRIPTION_MODEL', 'whisper-1'),
        'timeout_seconds' => (int) env('TRANSCRIPTION_TIMEOUT', 600),

        'prompt' => env('TRANSCRIPTION_PROMPT', 'Sho el Zbde. شو الزبدة؟ حكي لبناني عامي: شو، هيك، هلق، كتير، منيح، بدي، عم، لسا، يلا، حبيبي، إن شاء الله، دغري، بلشيت، ناطر، هيدا، مبلا، خلص، معليش، تكرم، بكرا، مبارح، شوي.'),

        // Per-minute list price. Config, not a constant, precisely so that a
        // price change or a different provider does not mean editing code.
        'cost_per_minute_usd' => (float) env('TRANSCRIPTION_COST_PER_MINUTE_USD', 0.006),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

    'accepted_mimes' => [
        'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/ogg',
        'audio/opus', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/flac',
        'audio/3gpp', 'audio/amr', 'video/mp4', 'video/webm',
    ],

];
