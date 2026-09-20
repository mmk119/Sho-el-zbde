<?php

namespace Tests\Feature\Web;

use App\Enums\VoiceNoteStatus;
use App\Livewire\ViewNote;
use App\Models\Digest;
use App\Models\Transcript;
use App\Models\VoiceNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ResultPageTest extends TestCase
{
    use RefreshDatabase;

    private function noteWithDigest(array $digestOverrides = [], array $noteOverrides = []): VoiceNote
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Done)->create(array_merge([
            'language_detected' => 'arabic',
        ], $noteOverrides));

        Transcript::create([
            'voice_note_id' => $note->id,
            'full_text' => 'انا عشت الحرب بلبنان كلها',
            'word_count' => 5,
        ]);

        Digest::create(array_merge([
            'voice_note_id' => $note->id,
            'summary' => ['الحرب بدأت فجأة'],
            'questions' => ['بتقدر تجي بكرا؟'],
            'entities' => ['dates' => [], 'times' => [], 'amounts' => ['400'], 'names' => [], 'places' => ['بيروت']],
            'action_items' => ['رد عليه'],
            'notes' => [],
            'urgency' => 'high',
        ], $digestOverrides));

        return $note->fresh();
    }

    public function test_the_result_page_shows_the_digest_and_the_transcript_together(): void
    {
        $note = $this->noteWithDigest();

        $this->get(route('notes.show', $note->public_token))
            ->assertOk()
            ->assertSee('El Zbde')
            ->assertSee('الحرب بدأت فجأة', false)
            ->assertSee('Full transcript')
            // The transcript must be on the same page as the digest - a
            // structurally valid digest can still be wrong, and the transcript
            // is how a reader checks.
            ->assertSee('انا عشت الحرب بلبنان كلها', false);
    }

    public function test_it_shows_the_urgency_and_the_facts_about_the_note(): void
    {
        $note = $this->noteWithDigest();

        $this->get(route('notes.show', $note->public_token))
            ->assertSee('High urgency')
            ->assertSee('Arabic')
            ->assertSee('5 words');
    }

    public function test_it_highlights_questions_and_has_a_friendly_empty_state(): void
    {
        $withQuestion = $this->noteWithDigest();

        $this->get(route('notes.show', $withQuestion->public_token))
            ->assertSee('Questions for you')
            ->assertSee('بتقدر تجي بكرا؟', false);

        $without = $this->noteWithDigest(['questions' => []]);

        $this->get(route('notes.show', $without->public_token))
            ->assertSee("they didn't ask you anything directly", false);
    }

    /** Quiet by design: a small line, never an error state. */
    public function test_unclear_notes_render_as_a_small_secondary_line(): void
    {
        $note = $this->noteWithDigest(['notes' => ['عوامل إقرمية', 'a garbled passage']]);

        $this->get(route('notes.show', $note->public_token))
            ->assertSee('Some parts were unclear (2)')
            ->assertDontSee('Error')
            ->assertDontSee('Warning');
    }

    public function test_a_clean_transcript_shows_no_unclear_line_at_all(): void
    {
        $note = $this->noteWithDigest(['notes' => []]);

        $this->get(route('notes.show', $note->public_token))
            ->assertDontSee('Some parts were unclear');
    }

    public function test_arabic_flips_the_content_to_rtl(): void
    {
        $note = $this->noteWithDigest();

        $this->assertTrue(Livewire::test(ViewNote::class, ['note' => $note])->instance()->isRtl());
    }

    public function test_english_stays_left_to_right(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Done)->create(['language_detected' => 'english']);
        Transcript::create(['voice_note_id' => $note->id, 'full_text' => 'hello there', 'word_count' => 2]);

        $this->assertFalse(Livewire::test(ViewNote::class, ['note' => $note->fresh()])->instance()->isRtl());
    }

    public function test_it_shows_the_four_steps_while_processing(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Transcribing)->create();

        $steps = Livewire::test(ViewNote::class, ['note' => $note])->instance()->steps();

        $this->assertCount(4, $steps);
        $this->assertSame('complete', $steps[0]['state']);
        $this->assertSame('active', $steps[1]['state']);
        $this->assertSame('pending', $steps[2]['state']);
    }

    public function test_a_failed_note_marks_the_step_that_failed(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Failed)->create([
            'error_message' => 'We could not transcribe this voice note.',
            'normalized_duration_seconds' => 180,
        ]);

        $steps = Livewire::test(ViewNote::class, ['note' => $note])->instance()->steps();

        $this->assertSame('failed', $steps[1]['state']);

        $this->get(route('notes.show', $note->public_token))
            ->assertSee('We could not transcribe this voice note.');
    }

    /** A failed digest must not take the transcript down with it. */
    public function test_a_failed_note_still_shows_the_transcript_it_managed_to_produce(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Failed)->create([
            'error_message' => 'We could not summarise it.',
        ]);
        Transcript::create(['voice_note_id' => $note->id, 'full_text' => 'نص موجود', 'word_count' => 2]);

        $this->get(route('notes.show', $note->public_token))
            ->assertSee('نص موجود', false);
    }

    public function test_the_copyable_digest_is_plain_text_without_the_transcript(): void
    {
        $note = $this->noteWithDigest();

        $text = Livewire::test(ViewNote::class, ['note' => $note])->instance()->plainTextDigest();

        $this->assertStringContainsString('الحرب بدأت فجأة', $text);
        $this->assertStringContainsString('Questions for you:', $text);
        $this->assertStringNotContainsString('انا عشت الحرب', $text, 'the transcript has its own copy button');
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get(route('notes.show', str_repeat('a', 40)))->assertNotFound();
    }
}
