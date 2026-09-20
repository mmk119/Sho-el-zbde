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
                'ar' => 'هلق عم بحكي معك شوي. يلا حبيبي، هيدا منيح كتير.',
            ],
            'shoelzbde.transcription.hint_templates' => [
                'default' => ':names.',
                'ar' => 'كنا عم نحكي عن :names.',
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

    // ── shape, not just content ─────────────────────────────────────────────

    /**
     * The regression that produced 48 words of repeated prompt instead of a
     * transcript. Whisper treats the prompt as the transcript of preceding
     * audio and continues it, so a bare comma list invites a comma list back.
     * Names must fold into a sentence.
     */
    public function test_names_are_folded_into_a_sentence_not_appended_as_a_list(): void
    {
        $prompt = TranscriptionPrompt::build('ar', 'القوات اللبنانية, بيروت');

        $this->assertStringContainsString('كنا عم نحكي عن', $prompt);
        $this->assertStringEndsWith('.', $prompt);
    }

    public function test_the_arabic_base_prompt_reads_as_speech_rather_than_a_glossary(): void
    {
        config(['shoelzbde.transcription.prompts.ar' => 'هلق عم بحكي معك شوي. يلا حبيبي، هيدا منيح كتير.']);

        $prompt = TranscriptionPrompt::build('ar');

        // A glossary is mostly separators. Prose is mostly words.
        $commas = preg_match_all('/[,،]/u', $prompt);
        $words = count(preg_split('/\s+/u', $prompt, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        $this->assertLessThan(
            $words / 3,
            $commas,
            'the prompt is comma-dense enough to read as a list to continue',
        );
    }

    public function test_a_trailing_separator_on_the_user_hint_does_not_double_up(): void
    {
        $prompt = TranscriptionPrompt::build('ar', 'بيروت، ');

        $this->assertStringNotContainsString('،.', $prompt);
        $this->assertStringNotContainsString('..', $prompt);
    }

    public function test_auto_detect_keeps_names_bare_so_the_language_is_not_steered(): void
    {
        $prompt = TranscriptionPrompt::build(null, 'Teta Mariam, Jounieh');

        // No English framing words that would bias detection.
        $this->assertStringNotContainsString('talking about', $prompt);
        $this->assertStringContainsString('Teta Mariam', $prompt);
    }

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

        // The base must survive; it is the part that carries the dialect.
        $this->assertStringContainsString('هلق عم بحكي', $prompt);
        $this->assertLessThanOrEqual(900, mb_strlen($prompt));
    }
}
