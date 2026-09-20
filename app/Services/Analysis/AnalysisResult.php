<?php

namespace App\Services\Analysis;

final readonly class AnalysisResult
{
    public function __construct(
        /** The decoded model output. Not yet trusted - DigestSchema validates it. */
        public ?array $payload,
        public string $model,
        public int $promptTokens,
        public int $completionTokens,
        /** True when the response body was not even valid JSON. */
        public bool $wasUnparseable = false,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function costUsd(): float
    {
        $in = (float) config('shoelzbde.analysis.cost_per_million_input_usd');
        $out = (float) config('shoelzbde.analysis.cost_per_million_output_usd');

        return round(
            ($this->promptTokens / 1_000_000) * $in + ($this->completionTokens / 1_000_000) * $out,
            6,
        );
    }
}
