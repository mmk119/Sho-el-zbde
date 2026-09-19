<?php

namespace Tests\Support;

use App\Contracts\TranscriptionService;
use App\Services\Transcription\TranscriptionResult;
use Throwable;

class FakeTranscriptionService implements TranscriptionService
{
    public ?string $sawPrompt = null;

    public ?string $sawLanguageHint = null;

    public int $calls = 0;

    public function __construct(
        private readonly ?TranscriptionResult $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function transcribe(string $absolutePath, string $prompt, ?string $languageHint = null): TranscriptionResult
    {
        $this->calls++;
        $this->sawPrompt = $prompt;
        $this->sawLanguageHint = $languageHint;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? new TranscriptionResult(
            text: 'مرحبا كيفك',
            language: 'arabic',
            segments: [['start' => 0, 'end' => 2, 'text' => 'مرحبا']],
            durationSeconds: 120.0,
            model: 'whisper-1',
        );
    }
}
