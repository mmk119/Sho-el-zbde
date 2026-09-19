<?php

namespace App\Services\Transcription;

use App\Contracts\TranscriptionService;
use App\Exceptions\TranscriptionRateLimited;
use App\Exceptions\TranscriptionRejected;
use App\Exceptions\TranscriptionUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenAiTranscriptionService implements TranscriptionService
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function transcribe(string $absolutePath, string $prompt, ?string $languageHint = null): TranscriptionResult
    {
        if (! is_file($absolutePath)) {
            throw new TranscriptionRejected('The audio file to transcribe is missing.');
        }

        if (trim($prompt) === '') {
            // Guards the invariant at the last possible moment. Reaching here
            // means a caller bypassed TranscriptionPrompt.
            throw new TranscriptionRejected('Refusing to transcribe without a prompt.');
        }

        $payload = [
            'model' => $this->model,
            'prompt' => $prompt,
            // verbose_json carries segments and the detected language. The
            // gpt-4o transcription models only speak plain json.
            'response_format' => str_starts_with($this->model, 'whisper') ? 'verbose_json' : 'json',
        ];

        if ($languageHint !== null && $languageHint !== '') {
            $payload['language'] = $languageHint;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->attach('file', fopen($absolutePath, 'r'), basename($absolutePath))
                ->post(rtrim($this->baseUrl, '/').'/audio/transcriptions', $payload);
        } catch (ConnectionException $e) {
            throw new TranscriptionUnavailable('Could not reach the transcription service.', 0, $e);
        }

        $this->guard($response);

        $body = $response->json();

        if (! is_array($body) || ! isset($body['text'])) {
            throw new TranscriptionUnavailable('The transcription service returned an unexpected response.');
        }

        return new TranscriptionResult(
            text: (string) $body['text'],
            language: $body['language'] ?? null,
            segments: $body['segments'] ?? null,
            durationSeconds: isset($body['duration']) ? (float) $body['duration'] : null,
            model: $this->model,
        );
    }

    /**
     * Map HTTP status onto the three outcomes the job knows how to handle:
     * slow down, try again later, or give up.
     */
    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        if ($status === 429) {
            throw new TranscriptionRateLimited(
                retryAfterSeconds: $this->retryAfter($response),
                message: 'The transcription service is rate limiting us.',
            );
        }

        // 408 request timeout and 5xx are worth another go.
        if ($status === 408 || $status >= 500) {
            throw new TranscriptionUnavailable("The transcription service returned HTTP {$status}.");
        }

        // 400, 401, 403, 413, 415, 422: the request will not become valid by
        // repeating it. Never log the response body - it can echo the prompt,
        // which in later phases carries user-supplied names.
        throw new TranscriptionRejected("The transcription service rejected the request (HTTP {$status}).");
    }

    private function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        if (is_numeric($header)) {
            return max(1, min((int) $header, 300));
        }

        // No usable header: back off a sane default rather than hammering.
        return 30;
    }
}
