<?php

namespace Tests\Feature\Web;

use App\Models\VoiceNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AudioStreamTest extends TestCase
{
    use RefreshDatabase;

    private function noteWithFile(): VoiceNote
    {
        Storage::fake('local');

        $note = VoiceNote::factory()->create(['original_filename' => 'teta.mp3']);
        Storage::put($note->storage_path, str_repeat('x', 5000));

        return $note;
    }

    /**
     * The result page puts this in an <audio> element. As an attachment the
     * browser downloads it instead of playing it.
     */
    public function test_audio_is_served_inline_not_as_a_download(): void
    {
        $note = $this->noteWithFile();

        $response = $this->get(route('voice-notes.audio', $note->public_token));

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    /** Without ranges the player cannot seek without pulling the whole file. */
    public function test_it_advertises_range_support(): void
    {
        $note = $this->noteWithFile();

        $this->get(route('voice-notes.audio', $note->public_token))
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    public function test_a_range_request_returns_partial_content(): void
    {
        $note = $this->noteWithFile();

        $this->get(route('voice-notes.audio', $note->public_token), ['Range' => 'bytes=0-99'])
            ->assertStatus(206)
            ->assertHeader('Content-Length', '100');
    }

    public function test_a_missing_file_is_a_404(): void
    {
        Storage::fake('local');
        $note = VoiceNote::factory()->create();

        $this->get(route('voice-notes.audio', $note->public_token))->assertNotFound();
    }
}
