<?php

namespace Tests\Feature\Analysis;

use App\Services\Analysis\AnalysisPrompt;
use Tests\TestCase;

/**
 * The prompt carries the Phase 0 findings. These assertions exist so that
 * someone tidying the wording cannot quietly delete the part that stops the
 * model inventing a name.
 */
class AnalysisPromptTest extends TestCase
{
    public function test_it_says_the_transcript_is_machine_generated_and_fallible(): void
    {
        $prompt = AnalysisPrompt::system();

        $this->assertStringContainsString('MACHINE TRANSCRIPT', $prompt);
        $this->assertStringContainsString('speech', $prompt);
        $this->assertStringContainsString('proper nouns', $prompt);
        $this->assertStringContainsString('code-switched', $prompt);
    }

    public function test_it_carries_the_real_examples_from_phase_zero(): void
    {
        $prompt = AnalysisPrompt::system();

        $this->assertStringContainsString('إقليمية', $prompt);
        $this->assertStringContainsString('اخرمية', $prompt);
        $this->assertStringContainsString('خطوط تماس', $prompt);
        $this->assertStringContainsString('خطوط تماث', $prompt);
    }

    public function test_it_forbids_guessing_and_points_at_notes(): void
    {
        $prompt = AnalysisPrompt::system();

        $this->assertStringContainsString('DO NOT GUESS', $prompt);
        $this->assertStringContainsString('notes', $prompt);
        $this->assertStringContainsString('Never invent', $prompt);
    }

    public function test_it_requires_the_speakers_own_language(): void
    {
        $prompt = AnalysisPrompt::system();

        $this->assertStringContainsString('SAME LANGUAGE', $prompt);
        $this->assertStringContainsString('Do not translate', $prompt);
    }

    /**
     * Regression: a general "use the speaker's language" instruction produced an
     * English summary of Arabic speech on the first live run. Naming the
     * detected language is what fixed it, so the name has to be in the prompt.
     */
    public function test_it_names_the_detected_language_explicitly(): void
    {
        $prompt = AnalysisPrompt::system('arabic');

        $this->assertStringContainsString('speaking arabic', $prompt);
        $this->assertStringContainsString('Write "summary" and "questions" in arabic', $prompt);
    }

    public function test_it_still_has_a_language_rule_when_detection_failed(): void
    {
        $prompt = AnalysisPrompt::system(null);

        $this->assertStringContainsString('SAME LANGUAGE', $prompt);
        $this->assertStringContainsString('Do not translate', $prompt);
    }

    public function test_the_stricter_reminder_is_about_shape_not_content(): void
    {
        $reminder = AnalysisPrompt::stricterReminder();

        $this->assertStringContainsString('Only fix the shape', $reminder);
        $this->assertStringContainsString('Do not change your findings', $reminder);
        $this->assertStringContainsString('notes', $reminder);
    }
}
