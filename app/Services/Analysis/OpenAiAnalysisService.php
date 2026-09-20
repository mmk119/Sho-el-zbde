<?php

namespace App\Services\Analysis;

use App\Contracts\AnalysisService;
use App\Exceptions\AnalysisRateLimited;
use App\Exceptions\AnalysisRejected;
use App\Exceptions\AnalysisUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Nothing in this class logs. The transcript and the prompt both carry private
 * content, and a response body can echo either back.
 */
class OpenAiAnalysisService implements AnalysisService
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function analyze(string $transcript, ?string $language = null, bool $stricter = false): AnalysisResult
    {
        $messages = [
            ['role' => 'system', 'content' => AnalysisPrompt::system($language)],
            ['role' => 'user', 'content' => AnalysisPrompt::userMessage($transcript)],
        ];

        if ($stricter) {
            $messages[] = ['role' => 'system', 'content' => AnalysisPrompt::stricterReminder()];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'response_format' => ['type' => 'json_object'],
                    // Low but not zero: this is extraction, not creative work.
                    'temperature' => 0.2,
                ]);
        } catch (ConnectionException $e) {
            throw new AnalysisUnavailable('Could not reach the analysis service.', 0, $e);
        }

        $this->guard($response);

        $body = $response->json();
        $content = $body['choices'][0]['message']['content'] ?? null;

        if (! is_string($content)) {
            throw new AnalysisUnavailable('The analysis service returned an unexpected response.');
        }

        $decoded = json_decode($content, true);

        return new AnalysisResult(
            payload: is_array($decoded) ? $decoded : null,
            model: $body['model'] ?? $this->model,
            promptTokens: (int) ($body['usage']['prompt_tokens'] ?? 0),
            completionTokens: (int) ($body['usage']['completion_tokens'] ?? 0),
            wasUnparseable: ! is_array($decoded),
        );
    }

    /** Same three outcomes as transcription: slow down, try later, or give up. */
    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        if ($status === 429) {
            throw new AnalysisRateLimited(
                retryAfterSeconds: $this->retryAfter($response),
                message: 'The analysis service is rate limiting us.',
            );
        }

        if ($status === 408 || $status >= 500) {
            throw new AnalysisUnavailable("The analysis service returned HTTP {$status}.");
        }

        // Never include the body - it can echo the transcript back at us.
        throw new AnalysisRejected("The analysis service rejected the request (HTTP {$status}).");
    }

    private function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        if (is_numeric($header)) {
            return max(1, min((int) $header, 300));
        }

        return 30;
    }
}
