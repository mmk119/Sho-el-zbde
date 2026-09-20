<?php

namespace Tests\Feature\Transcription;

use App\Services\Transcription\TranscriptionPrompt;
use Tests\TestCase;

/**
 * Two findings live in this class and they pull against each other:
 *
 *   - the prompt must never be empty (Phase 0: unprompted output hallucinates)
 *   - the prompt must not be in the wrong language (found by uploading an
 *     English note and getting an Arabic transcript back)
 *
 * These tests exist so neither can be broken while fixing the other.
 */
class TranscriptionPromptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shoelzbde.transcription.prompts' => [
                'default' => 'Sho el Zbde.',
                'ar' => 'Sho el Zbde. شو الزبدة؟ يلا، حبيبي، هيك',
            ],
        ]);
    }

    // ── never empty ─────────────────────────────────────────────────────────

    public function test_it_is_never_empty_for_any_language(): void
    {
        foreach ([null, '', 'ar', 'en', 'fr', 'zz'] as $hint) {
            $this->assertNotSame('', trim(TranscriptionPrompt::build($hint)));
        }
    }

    public function test_it_is_never_empty_even_if_config_is_blank(): void
    {
        config(['shoelzbde.transcription.prompts' => []]);

        $this->assertSame(TranscriptionPrompt::FALLBACK, TranscriptionPrompt::build(null));
        $this->assertSame(TranscriptionPrompt::FALLBACK, TranscriptionPrompt::build('ar'));
    }

    // ── the right language ──────────────────────────────────────────────────

    /**
     * The regression. A Whisper prompt is a language signal: an Arabic prompt
     * makes it translate English speech into Arabic. Anything that is not
     * explicitly Arabic must not receive Arabic vocabulary.
     */
    public function test_a_non_arabic_note_never_receives_arabic_vocabulary(): void
    {
        foreach ([null, 'en', 'fr', 'es', 'de'] as $hint) {
            $prompt = TranscriptionPrompt::build($hint);

            $this->assertDoesNotMatchRegularExpression(
                '/[\x{0600}-\x{06FF}]/u',
                $prompt,
                "language hint '".($hint ?? 'auto')."' must not get an Arabic prompt",
            );
        }
    }

    public function test_choosing_arabic_gets_the_dialect_vocabulary(): void
    {
        $prompt = TranscriptionPrompt::build('ar');

        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $prompt);
        $this->assertStringContainsString('يلا', $prompt);
    }

    public function test_auto_detect_gets_the_short_neutral_prompt(): void
    {
        $this->assertSame('Sho el Zbde.', TranscriptionPrompt::build(null));
    }

    public function test_an_unknown_language_falls_back_to_neutral_rather_than_failing(): void
    {
        $this->assertSame('Sho el Zbde.', TranscriptionPrompt::build('xx'));
    }

    public function test_the_language_hint_is_matched_case_insensitively(): void
    {
        $this->assertSame(TranscriptionPrompt::build('ar'), TranscriptionPrompt::build('AR'));
    }

    // ── the uploader's own names ────────────────────────────────────────────

    public function test_it_appends_the_user_hint_without_losing_the_base(): void
    {
        $prompt = TranscriptionPrompt::build('ar', 'Teta Mariam, Jounieh');

        $this->assertStringContainsString('يلا', $prompt);
        $this->assertStringContainsString('Teta Mariam', $prompt);
    }

    public function test_the_user_hint_works_on_auto_detect_too(): void
    {
        $prompt = TranscriptionPrompt::build(null, 'Roger, Q1, Q2');

        $this->assertStringContainsString('Roger', $prompt);
        $this->assertStringContainsString('Sho el Zbde', $prompt);
    }

    public function test_a_long_user_hint_is_trimmed_so_the_base_survives(): void
    {
        $prompt = TranscriptionPrompt::build('ar', str_repeat('name ', 400));

        $this->assertStringContainsString('Sho el Zbde', $prompt);
        $this->assertLessThanOrEqual(900, mb_strlen($prompt));
    }
}
