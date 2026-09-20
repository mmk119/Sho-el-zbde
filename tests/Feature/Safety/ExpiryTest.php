<?php

namespace Tests\Feature\Safety;

use App\Enums\VoiceNoteStatus;
use App\Models\Digest;
use App\Models\Transcript;
use App\Models\VoiceNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function expiredNote(): VoiceNote
    {
        Storage::fake('local');

        $note = VoiceNote::factory()->status(VoiceNoteStatus::Done)->create([
            'expires_at' => now()->subDay(),
            'language_detected' => 'arabic',
        ]);

        Storage::put($note->storage_path, 'audio bytes');

        Transcript::create([
            'voice_note_id' => $note->id,
            'full_text' => 'نص سري',
            'word_count' => 2,
        ]);

        Digest::create([
            'voice_note_id' => $note->id,
            'summary' => ['سر'],
            'questions' => [],
            'entities' => ['dates' => [], 'times' => [], 'amounts' => [], 'names' => [], 'places' => []],
            'action_items' => [],
            'notes' => [],
            'urgency' => 'normal',
        ]);

        return $note->fresh();
    }

    public function test_the_result_page_says_plainly_that_it_is_gone(): void
    {
        $note = $this->expiredNote();

        $this->get(route('notes.show', $note->public_token))
            ->assertOk()
            ->assertSee("This one's gone", false)
            ->assertSee('deleted after 30 days');
    }

    /** Expiry must hide the content even before the cleanup job has run. */
    public function test_an_expired_page_leaks_neither_digest_nor_transcript(): void
    {
        $note = $this->expiredNote();

        $this->get(route('notes.show', $note->public_token))
            ->assertDontSee('نص سري', false)
            ->assertDontSee('El Zbde');
    }

    public function test_the_api_returns_410_with_a_readable_message(): void
    {
        $note = $this->expiredNote();

        $this->getJson(route('voice-notes.show', $note->public_token))
            ->assertStatus(410)
            ->assertJsonPath('status', 'expired')
            ->assertJsonFragment(['message' => 'This voice note has passed its 30 day retention window and has been deleted.']);
    }

    public function test_expired_audio_is_no_longer_served(): void
    {
        $note = $this->expiredNote();

        $this->get(route('voice-notes.audio', $note->public_token))->assertStatus(410);
    }

    public function test_a_note_inside_its_window_is_unaffected(): void
    {
        Storage::fake('local');

        $note = VoiceNote::factory()->status(VoiceNoteStatus::Done)->create([
            'expires_at' => now()->addDays(5),
        ]);

        $this->assertFalse($note->isExpired());
        $this->getJson(route('voice-notes.show', $note->public_token))->assertOk();
    }

    public function test_notes_default_to_the_configured_retention_window(): void
    {
        $note = VoiceNote::factory()->create(['expires_at' => null]);

        $this->assertNotNull($note->expires_at);
        $this->assertSame(
            config('shoelzbde.retention_days'),
            (int) round(now()->diffInDays($note->expires_at, absolute: true)),
        );
    }
}
