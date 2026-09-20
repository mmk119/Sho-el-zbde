<?php

namespace Tests\Support;

use App\Contracts\AnalysisService;
use App\Services\Analysis\AnalysisResult;
use Throwable;

class FakeAnalysisService implements AnalysisService
{
    /** @var array<int, bool> which attempts asked for stricter output */
    public array $sawStricter = [];

    public ?string $sawTranscript = null;

    public ?string $sawLanguage = null;

    public int $calls = 0;

    /** @param array<int, AnalysisResult> $queue one result per attempt */
    public function __construct(
        private readonly array $queue = [],
        private readonly ?Throwable $throws = null,
    ) {
    }

    public static function valid(array $overrides = []): array
    {
        return array_merge([
            'summary' => ['بيحكي عن الحرب'],
            'questions' => [],
            'entities' => [
                'dates' => [], 'times' => [], 'amounts' => [],
                'names' => [], 'places' => ['بيروت'],
            ],
            'action_items' => [],
            'urgency' => 'normal',
            'notes' => [],
        ], $overrides);
    }

    public static function result(?array $payload, bool $unparseable = false): AnalysisResult
    {
        return new AnalysisResult(
            payload: $payload,
            model: 'gpt-4o-test',
            promptTokens: 1000,
            completionTokens: 200,
            wasUnparseable: $unparseable,
        );
    }

    public function analyze(string $transcript, ?string $language = null, bool $stricter = false): AnalysisResult
    {
        $this->calls++;
        $this->sawStricter[] = $stricter;
        $this->sawTranscript = $transcript;
        $this->sawLanguage = $language;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->queue[$this->calls - 1] ?? self::result(self::valid());
    }
}
