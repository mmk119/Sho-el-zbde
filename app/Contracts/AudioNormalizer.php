<?php

namespace App\Contracts;

use App\Services\Audio\NormalizedAudio;

interface AudioNormalizer
{
    /**
     * Produce the audio we actually send for transcription.
     *
     * Implementations must not modify the source file - the original is what the
     * user plays back, and duration_seconds is measured against it.
     *
     * @throws \App\Exceptions\AudioNormalizationFailed
     */
    public function normalize(string $sourceAbsolutePath, string $targetAbsolutePath): NormalizedAudio;
}
