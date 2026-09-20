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
    | into Arabic text.
    |
    | Two things about it, both learned the hard way by running it:
    |
    | 1. A prompt is a LANGUAGE signal. Whisper will translate English speech
    |    into the language its prompt is written in, so prompts are keyed by
    |    language and auto-detect gets a short, script-neutral one.
    |
    | 2. A prompt is a CONTINUATION, not an instruction. Whisper treats it as
    |    the transcript of audio that came just before, and carries on from it.
    |    A dense comma-separated glossary therefore reads as a list to continue,
    |    and the model obliges - emitting the list again instead of transcribing.
    |    So the prompts below are natural speech that happens to contain the
    |    vocabulary, and names are folded into a sentence rather than appended
    |    to a list. Shape matters more than length.
    |
    */

    'transcription' => [
        'driver' => env('TRANSCRIPTION_DRIVER', 'openai'),
        'model' => env('TRANSCRIPTION_MODEL', 'whisper-1'),
        'timeout_seconds' => (int) env('TRANSCRIPTION_TIMEOUT', 600),

        'prompts' => [
            // Auto-detect. Deliberately minimal: anything longer steers the
            // output language before we know what the speaker is using.
            'default' => env('TRANSCRIPTION_PROMPT', 'Sho el Zbde.'),

            // Used only when the uploader selects Arabic. Ordinary Lebanese
            // speech carrying the dialect markers Phase 0 found load-bearing.
            'ar' => env('TRANSCRIPTION_PROMPT_AR', 'مرحبا، كيفك؟ هلق عم بحكي معك شوي. شو صار معك مبارح؟ كنت ناطر كتير، بس معليش. هيدا الشي منيح كتير بس لسا ما خلص. يلا حبيبي، إن شاء الله بكرا منشوفك ودغري منرجع عالبيت.'),
        ],

        /*
         * How the uploader's names and places get folded in. :names is replaced
         * with what they typed.
         *
         * The default is bare on purpose: any framing words would be a language
         * signal, and on auto-detect we do not yet know the language. Two or
         * three proper nouns are not enough of a pattern to invite a list.
         */
        'hint_templates' => [
            'default' => env('TRANSCRIPTION_HINT_TEMPLATE', ':names.'),
            'ar' => env('TRANSCRIPTION_HINT_TEMPLATE_AR', 'كنا عم نحكي عن :names.'),
        ],

        /*
         * Sanity checks on what comes back, so a prompt echo or a collapsed
         * transcript is refused rather than stored. See TranscriptSanityCheck.
         */
        'min_words_per_minute' => (int) env('TRANSCRIPTION_MIN_WORDS_PER_MINUTE', 25),
        'max_prompt_overlap' => (float) env('TRANSCRIPTION_MAX_PROMPT_OVERLAP', 0.6),
        'sanity_check_from_seconds' => (int) env('TRANSCRIPTION_SANITY_FROM_SECONDS', 30),

        // Per-minute list price. Config, not a constant, precisely so that a
        // price change or a different provider does not mean editing code.
        'cost_per_minute_usd' => (float) env('TRANSCRIPTION_COST_PER_MINUTE_USD', 0.006),
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis
    |--------------------------------------------------------------------------
    |
    | Priced per token rather than per minute, which is why usage_logs keeps the
    | two steps apart.
    |
    */

    'analysis' => [
        'driver' => env('ANALYSIS_DRIVER', 'openai'),
        'model' => env('ANALYSIS_MODEL', 'gpt-4o'),
        'timeout_seconds' => (int) env('ANALYSIS_TIMEOUT', 180),
        'cost_per_million_input_usd' => (float) env('ANALYSIS_COST_PER_MILLION_INPUT_USD', 2.50),
        'cost_per_million_output_usd' => (float) env('ANALYSIS_COST_PER_MILLION_OUTPUT_USD', 10.00),
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
