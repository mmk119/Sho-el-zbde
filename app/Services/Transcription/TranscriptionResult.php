<?php

namespace App\Services\Transcription;

final readonly class TranscriptionResult
{
    public function __construct(
        public string $text,
        public ?string $language,
        /**
         * Stored because it is cheap, but the Phase 0 finding stands: segment
         * boundaries move when the prompt changes, so nothing may depend on
         * them. Null for models that do not return them at all.
         */
        public ?array $segments,
        public ?float $durationSeconds,
        public string $model,
    ) {
    }

    public function wordCount(): int
    {
        $words = preg_split('/\s+/u', trim($this->text), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? count($words) : 0;
    }
}
