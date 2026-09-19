<?php

namespace Tests\Feature\Transcription;

use App\Exceptions\TranscriptionRateLimited;
use App\Exceptions\TranscriptionRejected;
use App\Exceptions\TranscriptionUnavailable;
use App\Services\Transcription\OpenAiTranscriptionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiTranscriptionServiceTest extends TestCase
{
    private string $audio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audio = tempnam(sys_get_temp_dir(), 'zbde').'.wav';
        file_put_contents($this->audio, 'not really audio');
    }

    protected function tearDown(): void
    {
        @unlink($this->audio);

        parent::tearDown();
    }

    private function service(): OpenAiTranscriptionService
    {
        return new OpenAiTranscriptionService('sk-test', 'https://api.openai.com/v1', 'whisper-1', 30);
    }

    public function test_it_parses_a_successful_verbose_json_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'text' => 'مرحبا كيفك اليوم',
                'language' => 'arabic',
                'duration' => 12.5,
                'segments' => [['start' => 0, 'end' => 2, 'text' => 'مرحبا']],
            ]),
        ]);

        $result = $this->service()->transcribe($this->audio, 'Sho el Zbde', 'ar');

        $this->assertSame('arabic', $result->language);
        $this->assertSame(12.5, $result->durationSeconds);
        $this->assertCount(1, $result->segments);
        $this->assertSame(3, $result->wordCount());
    }

    public function test_it_always_sends_the_prompt(): void
    {
        Http::fake(['*' => Http::response(['text' => 'ok'])]);

        $this->service()->transcribe($this->audio, 'Sho el Zbde, yalla');

        Http::assertSent(function ($request) {
            $body = collect($request->data());
            $prompt = $body->firstWhere('name', 'prompt')['contents'] ?? null;

            return $prompt === 'Sho el Zbde, yalla';
        });
    }

    public function test_it_refuses_to_call_out_with_an_empty_prompt(): void
    {
        Http::fake();

        $this->expectException(TranscriptionRejected::class);

        $this->service()->transcribe($this->audio, '   ');

        Http::assertNothingSent();
    }

    public function test_a_429_becomes_rate_limited_and_honours_retry_after(): void
    {
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '45'])]);

        try {
            $this->service()->transcribe($this->audio, 'prompt');
            $this->fail('expected TranscriptionRateLimited');
        } catch (TranscriptionRateLimited $e) {
            $this->assertSame(45, $e->retryAfterSeconds);
        }
    }

    public function test_a_429_without_a_usable_header_falls_back_to_a_sane_delay(): void
    {
        Http::fake(['*' => Http::response('slow down', 429)]);

        try {
            $this->service()->transcribe($this->audio, 'prompt');
            $this->fail('expected TranscriptionRateLimited');
        } catch (TranscriptionRateLimited $e) {
            $this->assertSame(30, $e->retryAfterSeconds);
        }
    }

    public function test_an_absurd_retry_after_is_clamped(): void
    {
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '99999'])]);

        try {
            $this->service()->transcribe($this->audio, 'prompt');
            $this->fail('expected TranscriptionRateLimited');
        } catch (TranscriptionRateLimited $e) {
            $this->assertSame(300, $e->retryAfterSeconds);
        }
    }

    public function test_a_500_is_transient(): void
    {
        Http::fake(['*' => Http::response('boom', 503)]);

        $this->expectException(TranscriptionUnavailable::class);

        $this->service()->transcribe($this->audio, 'prompt');
    }

    public function test_a_401_is_permanent(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        $this->expectException(TranscriptionRejected::class);

        $this->service()->transcribe($this->audio, 'prompt');
    }

    public function test_it_does_not_leak_the_response_body_into_the_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'secret-prompt-echo']], 400)]);

        try {
            $this->service()->transcribe($this->audio, 'prompt');
            $this->fail('expected TranscriptionRejected');
        } catch (TranscriptionRejected $e) {
            $this->assertStringNotContainsString('secret-prompt-echo', $e->getMessage());
        }
    }
}
