<?php

namespace App\Services\Audio;

final readonly class NormalizedAudio
{
    public function __construct(
        public string $absolutePath,
        /**
         * Duration of the NORMALIZED audio, after silence trimming. This is what
         * gets sent for transcription and therefore what we are billed on, so it
         * is the number that lands in voice_notes.normalized_duration_seconds
         * and usage_logs.duration_seconds.
         */
        public int $durationSeconds,
        public int $bytes,
    ) {
    }
}
