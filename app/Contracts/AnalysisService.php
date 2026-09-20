<?php

namespace App\Contracts;

use App\Services\Analysis\AnalysisResult;

interface AnalysisService
{
    /**
     * Turn a transcript into a structured digest.
     *
     * Implementations return whatever the model produced, decoded. They do NOT
     * decide whether it is valid - that is DigestSchema's job, so that the same
     * rules apply no matter which provider is bound.
     *
     * $language is the language the speaker actually used, as detected during
     * transcription. Naming it explicitly is the difference between a summary in
     * the speaker's language and a summary silently translated into English - a
     * general "use their language" instruction was measured as not enough.
     *
     * $stricter asks for a second, more insistent attempt after a schema
     * failure. It exists on the interface rather than inside a driver so the
     * retry-once rule survives a provider swap.
     *
     * @throws \App\Exceptions\AnalysisRateLimited   caller should retry later
     * @throws \App\Exceptions\AnalysisUnavailable   transient, retry
     * @throws \App\Exceptions\AnalysisRejected      permanent, do not retry
     */
    public function analyze(string $transcript, ?string $language = null, bool $stricter = false): AnalysisResult;
}
