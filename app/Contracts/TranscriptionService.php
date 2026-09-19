<?php

namespace App\Contracts;

use App\Services\Transcription\TranscriptionResult;

interface TranscriptionService
{
    /**
     * Transcribe an audio file.
     *
     * $prompt is required, not optional. Phase 0 established that the
     * unprompted path hallucinates an opening phrase and leaks non-Arabic
     * characters into Arabic text, so there is deliberately no way to express
     * "no prompt" through this interface.
     *
     * @throws \App\Exceptions\TranscriptionRateLimited      caller should retry later
     * @throws \App\Exceptions\TranscriptionUnavailable      transient, retry
     * @throws \App\Exceptions\TranscriptionRejected         permanent, do not retry
     */
    public function transcribe(string $absolutePath, string $prompt, ?string $languageHint = null): TranscriptionResult;
}
