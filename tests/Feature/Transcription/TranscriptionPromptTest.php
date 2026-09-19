<?php

namespace Tests\Feature\Transcription;

use App\Services\Transcription\TranscriptionPrompt;
use Tests\TestCase;

/**
 * The Phase 0 finding that shaped Phase 2: the unprompted path hallucinates.
 * These tests exist to make an empty prompt impossible to ship.
 */
class TranscriptionPromptTest extends TestCase
{
    public function test_it_is_never_empty_with_no_user_hint(): void
    {
        $this->assertNotSame('', TranscriptionPrompt::build(null));
    }

    public function test_it_is_never_empty_even_if_config_is_blank(): void
    {
        config(['shoelzbde.transcription.prompt' => '']);

        $this->assertSame(TranscriptionPrompt::FALLBACK, TranscriptionPrompt::build(null));
        $this->assertSame(TranscriptionPrompt::FALLBACK, TranscriptionPrompt::build('   '));
    }

    public function test_it_carries_the_configured_dialect_vocabulary(): void
    {
        config(['shoelzbde.transcription.prompt' => 'Sho el Zbde, yalla, habibi']);

        $this->assertStringContainsString('yalla', TranscriptionPrompt::build(null));
    }

    public function test_it_appends_the_user_hint_without_losing_the_default(): void
    {
        config(['shoelzbde.transcription.prompt' => 'Sho el Zbde, yalla']);

        $prompt = TranscriptionPrompt::build('Teta Mariam, Jounieh');

        $this->assertStringContainsString('yalla', $prompt);
        $this->assertStringContainsString('Teta Mariam', $prompt);
    }

    public function test_a_long_user_hint_is_trimmed_from_the_end_so_the_default_survives(): void
    {
        config(['shoelzbde.transcription.prompt' => 'Sho el Zbde, yalla']);

        $prompt = TranscriptionPrompt::build(str_repeat('name ', 400));

        $this->assertStringContainsString('Sho el Zbde', $prompt);
        $this->assertLessThanOrEqual(900, mb_strlen($prompt));
    }
}
