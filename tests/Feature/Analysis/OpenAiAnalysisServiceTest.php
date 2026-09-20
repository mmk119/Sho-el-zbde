<?php

namespace Tests\Feature\Analysis;

use App\Exceptions\AnalysisRateLimited;
use App\Exceptions\AnalysisRejected;
use App\Exceptions\AnalysisUnavailable;
use App\Services\Analysis\OpenAiAnalysisService;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAnalysisService;
use Tests\TestCase;

class OpenAiAnalysisServiceTest extends TestCase
{
    private function service(): OpenAiAnalysisService
    {
        return new OpenAiAnalysisService('sk-test', 'https://api.openai.com/v1', 'gpt-4o', 30);
    }

    private function completion(array $payload, int $in = 1200, int $out = 180): array
    {
        return [
            'model' => 'gpt-4o-2024-11-20',
            'choices' => [['message' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
        ];
    }

    public function test_it_decodes_a_well_formed_response(): void
    {
        Http::fake(['*' => Http::response($this->completion(FakeAnalysisService::valid()))]);

        $result = $this->service()->analyze('نص');

        $this->assertFalse($result->wasUnparseable);
        $this->assertSame('normal', $result->payload['urgency']);
        $this->assertSame('gpt-4o-2024-11-20', $result->model);
        $this->assertSame(1380, $result->totalTokens());
    }

    public function test_it_reports_token_cost_from_config(): void
    {
        config([
            'shoelzbde.analysis.cost_per_million_input_usd' => 2.50,
            'shoelzbde.analysis.cost_per_million_output_usd' => 10.00,
        ]);

        Http::fake(['*' => Http::response($this->completion(FakeAnalysisService::valid(), 1_000_000, 1_000_000))]);

        $this->assertEqualsWithDelta(12.50, $this->service()->analyze('نص')->costUsd(), 0.000001);
    }

    public function test_unparseable_content_is_flagged_rather_than_thrown(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => 'here is your digest: {oops']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
        ])]);

        $result = $this->service()->analyze('نص');

        $this->assertTrue($result->wasUnparseable);
        $this->assertNull($result->payload);
    }

    public function test_the_stricter_pass_adds_a_shape_reminder(): void
    {
        Http::fake(['*' => Http::response($this->completion(FakeAnalysisService::valid()))]);

        $this->service()->analyze('نص', 'arabic', stricter: true);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'];

            return count($messages) === 3
                && str_contains($messages[2]['content'], 'Only fix the shape');
        });
    }

    public function test_the_normal_pass_does_not_include_the_reminder(): void
    {
        Http::fake(['*' => Http::response($this->completion(FakeAnalysisService::valid()))]);

        $this->service()->analyze('نص');

        Http::assertSent(fn ($request) => count($request->data()['messages']) === 2);
    }

    public function test_it_asks_for_a_json_object(): void
    {
        Http::fake(['*' => Http::response($this->completion(FakeAnalysisService::valid()))]);

        $this->service()->analyze('نص');

        Http::assertSent(fn ($request) => $request->data()['response_format']['type'] === 'json_object');
    }

    public function test_a_429_becomes_rate_limited_with_a_clamped_delay(): void
    {
        Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '99999'])]);

        try {
            $this->service()->analyze('نص');
            $this->fail('expected AnalysisRateLimited');
        } catch (AnalysisRateLimited $e) {
            $this->assertSame(300, $e->retryAfterSeconds);
        }
    }

    public function test_a_5xx_is_transient(): void
    {
        Http::fake(['*' => Http::response('boom', 502)]);

        $this->expectException(AnalysisUnavailable::class);

        $this->service()->analyze('نص');
    }

    public function test_a_401_is_permanent(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        $this->expectException(AnalysisRejected::class);

        $this->service()->analyze('نص');
    }

    /** The body can echo the transcript straight back at us. */
    public function test_it_never_puts_the_response_body_in_the_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'private transcript text']], 400)]);

        try {
            $this->service()->analyze('نص');
            $this->fail('expected AnalysisRejected');
        } catch (AnalysisRejected $e) {
            $this->assertStringNotContainsString('private transcript', $e->getMessage());
        }
    }
}
