<?php

namespace Tests\Feature\Analysis;

use App\Services\Analysis\DigestSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Support\FakeAnalysisService;

class DigestSchemaTest extends TestCase
{
    public function test_a_complete_payload_is_valid(): void
    {
        $this->assertSame([], DigestSchema::errors(FakeAnalysisService::valid()));
    }

    /**
     * The reason notes exists at all. An omitted notes key is indistinguishable
     * from "the transcript was perfectly clear", which is exactly the confusion
     * the field was added to prevent.
     */
    public function test_a_missing_notes_key_is_rejected_even_though_empty_is_allowed(): void
    {
        $payload = FakeAnalysisService::valid();
        unset($payload['notes']);

        $this->assertContains('missing key: notes', DigestSchema::errors($payload));

        $withEmpty = FakeAnalysisService::valid(['notes' => []]);
        $this->assertSame([], DigestSchema::errors($withEmpty));
    }

    #[DataProvider('requiredKeys')]
    public function test_every_top_level_key_is_required(string $key): void
    {
        $payload = FakeAnalysisService::valid();
        unset($payload[$key]);

        $this->assertContains("missing key: {$key}", DigestSchema::errors($payload));
    }

    public static function requiredKeys(): array
    {
        return [
            ['summary'], ['questions'], ['entities'],
            ['action_items'], ['urgency'], ['notes'],
        ];
    }

    #[DataProvider('entityKeys')]
    public function test_every_entity_bucket_is_required(string $key): void
    {
        $payload = FakeAnalysisService::valid();
        unset($payload['entities'][$key]);

        $this->assertContains("missing key: entities.{$key}", DigestSchema::errors($payload));
    }

    public static function entityKeys(): array
    {
        return [['dates'], ['times'], ['amounts'], ['names'], ['places']];
    }

    public function test_urgency_must_be_one_of_the_three_values(): void
    {
        $payload = FakeAnalysisService::valid(['urgency' => 'critical']);

        $this->assertNotSame([], DigestSchema::errors($payload));
        $this->assertFalse(DigestSchema::isValid($payload));
    }

    public function test_null_instead_of_an_empty_array_is_rejected(): void
    {
        $payload = FakeAnalysisService::valid(['questions' => null]);

        $this->assertFalse(DigestSchema::isValid($payload));
    }

    public function test_a_list_of_objects_where_strings_belong_is_rejected(): void
    {
        $payload = FakeAnalysisService::valid(['summary' => [['text' => 'nope']]]);

        $this->assertFalse(DigestSchema::isValid($payload));
    }

    public function test_entities_must_be_an_object_not_a_list(): void
    {
        $payload = FakeAnalysisService::valid(['entities' => ['a', 'b']]);

        $this->assertContains('entities must be an object', DigestSchema::errors($payload));
    }

    public function test_a_non_object_response_is_rejected(): void
    {
        $this->assertFalse(DigestSchema::isValid('just a string'));
        $this->assertFalse(DigestSchema::isValid(null));
    }

    public function test_normalize_drops_keys_the_model_invented(): void
    {
        $payload = FakeAnalysisService::valid([
            'sentiment' => 'wistful',
            'confidence' => 0.8,
        ]);
        $payload['entities']['weapons'] = ['rifle'];

        $normalized = DigestSchema::normalize($payload);

        $this->assertArrayNotHasKey('sentiment', $normalized);
        $this->assertArrayNotHasKey('confidence', $normalized);
        $this->assertSame(DigestSchema::ENTITY_KEYS, array_keys($normalized['entities']));
    }

    /**
     * Bullet count is prompt guidance, not a schema rule. Failing a genuinely
     * short note for having two bullets would turn a good digest into a failed
     * note.
     */
    public function test_a_short_summary_is_not_a_schema_failure(): void
    {
        $this->assertTrue(DigestSchema::isValid(FakeAnalysisService::valid(['summary' => ['one']])));
    }
}
