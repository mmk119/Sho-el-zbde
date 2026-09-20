<?php

namespace Tests\Feature\Transcription;

use App\Services\Transcription\TranscriptSanityCheck;
use Tests\TestCase;

/**
 * The guard against storing a transcript that is not a transcription.
 *
 * Measured on the real failure: a prompt echo came back 48 words for 3:00 of
 * speech with 75% of its words taken from the prompt, where a genuine
 * transcript of the same audio was 252 words at 9% overlap. The thresholds sit
 * in that gap with room on both sides.
 */
class TranscriptSanityCheckTest extends TestCase
{
    private const PROMPT = 'مرحبا، كيفك؟ هلق عم بحكي معك شوي. شو صار معك مبارح؟ كنت ناطر كتير، بس معليش.';

    /** Roughly 85 words a minute of ordinary speech. */
    private function realTranscript(int $words = 250): string
    {
        return trim(str_repeat('انا عشت الحرب بلبنان وكانت صعبة كتير على كل العالم ', (int) ceil($words / 10)));
    }

    public function test_a_real_transcript_passes(): void
    {
        $this->assertNull(
            TranscriptSanityCheck::problem($this->realTranscript(250), self::PROMPT, 180),
        );
    }

    public function test_an_empty_transcription_is_refused(): void
    {
        $this->assertNotNull(TranscriptSanityCheck::problem('', self::PROMPT, 180));
        $this->assertNotNull(TranscriptSanityCheck::problem("   \n  ", self::PROMPT, 180));
    }

    // ── the collapse ────────────────────────────────────────────────────────

    public function test_a_transcript_far_too_sparse_for_the_audio_is_refused(): void
    {
        // 48 words for three minutes - the real failure, 16 words a minute.
        $problem = TranscriptSanityCheck::problem(
            trim(str_repeat('كلمة ', 48)),
            'unrelated prompt text',
            180,
        );

        $this->assertNotNull($problem);
        $this->assertStringContainsString('too sparse', $problem);
    }

    public function test_ordinary_speech_clears_the_sparseness_floor_comfortably(): void
    {
        // 84 words a minute, which is slow but entirely normal.
        $this->assertNull(
            TranscriptSanityCheck::problem($this->realTranscript(250), 'unrelated prompt', 180),
        );
    }

    /** A five second "call me back" is legitimately a handful of words. */
    public function test_short_clips_are_exempt_from_the_rate_check(): void
    {
        $this->assertNull(TranscriptSanityCheck::problem('call me back', 'unrelated prompt', 8));
    }

    public function test_an_unknown_duration_skips_the_rate_check(): void
    {
        $this->assertNull(TranscriptSanityCheck::problem('a few words only', 'unrelated prompt', null));
    }

    // ── the echo ────────────────────────────────────────────────────────────

    public function test_a_transcript_made_of_the_prompt_is_refused(): void
    {
        // What the failure actually looked like: the prompt, over and over.
        $echo = trim(str_repeat('القوات اللبنانية, بيروت, الشوف ', 40));

        $problem = TranscriptSanityCheck::problem(
            $echo,
            'كنا عم نحكي عن القوات اللبنانية, بيروت, الشوف.',
            180,
        );

        $this->assertNotNull($problem);
        $this->assertStringContainsString('repeated the prompt', $problem);
    }

    /**
     * The prompt is deliberately chosen to contain words the speaker will use,
     * so some overlap is the point. It must not be mistaken for an echo.
     */
    public function test_sharing_common_words_with_the_prompt_is_not_an_echo(): void
    {
        $natural = trim(str_repeat('هلق عم بحكي معك عن شغلة تانية خالص وهيدا مهم ', 25));

        $this->assertNull(TranscriptSanityCheck::problem($natural, self::PROMPT, 180));
    }

    public function test_an_empty_prompt_cannot_trigger_the_echo_check(): void
    {
        $this->assertNull(TranscriptSanityCheck::problem($this->realTranscript(250), '', 180));
    }

    // ── thresholds are config, not constants ────────────────────────────────

    public function test_the_thresholds_can_be_tuned_without_touching_code(): void
    {
        $sparse = trim(str_repeat('كلمة ', 48));

        config(['shoelzbde.transcription.min_words_per_minute' => 5]);
        $this->assertNull(TranscriptSanityCheck::problem($sparse, 'unrelated', 180));

        config(['shoelzbde.transcription.min_words_per_minute' => 200]);
        $this->assertNotNull(TranscriptSanityCheck::problem($this->realTranscript(250), 'unrelated', 180));
    }
}
