<?php

namespace Tests\Feature\Safety;

use App\Contracts\TranscriptionService;
use App\Enums\VoiceNoteStatus;
use App\Jobs\TranscribeAudio;
use App\Models\Transcript;
use App\Models\VoiceNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTranscriptionService;
use Tests\TestCase;

/**
 * The normalized wav is uncompressed 16kHz PCM - reliably larger than the
 * compressed original it came from - and it has no purpose once the text
 * exists.
 */
class NormalizedAudioCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function readyNote(): VoiceNote
    {
        Storage::fake('local');

        $note = VoiceNote::factory()->status(VoiceNoteStatus::Transcribing)->create([
            'normalized_storage_path' => 'voice-notes/normalized/n.wav',
            'normalized_duration_seconds' => 461,
        ]);

        Storage::put($note->storage_path, 'original');
        Storage::put('voice-notes/normalized/n.wav', 'normalized');

        $this->app->bind(TranscriptionService::class, fn () => new FakeTranscriptionService());

        return $note->fresh();
    }

    public function test_the_normalized_file_is_deleted_once_transcription_succeeds(): void
    {
        $note = $this->readyNote();

        $this->app->call([new TranscribeAudio($note), 'handle']);

        Storage::assertMissing('voice-notes/normalized/n.wav');
        $this->assertNull($note->fresh()->normalized_storage_path);
    }

    /** The original is what the result page plays back. It must survive. */
    public function test_the_original_upload_is_kept(): void
    {
        $note = $this->readyNote();

        $this->app->call([new TranscribeAudio($note), 'handle']);

        Storage::assertExists($note->storage_path);
    }

    /** It is the billing number; it must outlive the file it was measured from. */
    public function test_the_normalized_duration_survives_the_file(): void
    {
        $note = $this->readyNote();

        $this->app->call([new TranscribeAudio($note), 'handle']);

        $this->assertSame(461, $note->fresh()->normalized_duration_seconds);
    }

    /**
     * Deleting the file makes the job non-repeatable unless it notices the work
     * is already done. Without this a retry would read "file missing" and fail
     * a note whose transcript is sitting right there.
     */
    public function test_rerunning_after_the_file_is_gone_does_not_fail_the_note(): void
    {
        $note = $this->readyNote();

        $this->app->call([new TranscribeAudio($note), 'handle']);
        $this->app->call([new TranscribeAudio($note->fresh()), 'handle']);

        $fresh = $note->fresh();

        $this->assertSame(VoiceNoteStatus::Analyzing, $fresh->status);
        $this->assertNotNull($fresh->transcript);
    }

    public function test_it_does_not_transcribe_twice(): void
    {
        $note = $this->readyNote();

        $fake = new FakeTranscriptionService();
        $this->app->bind(TranscriptionService::class, fn () => $fake);

        $this->app->call([new TranscribeAudio($note), 'handle']);
        $this->app->call([new TranscribeAudio($note->fresh()), 'handle']);

        $this->assertSame(1, $fake->calls, 'the second run must not pay for another transcription');
        $this->assertSame(1, Transcript::where('voice_note_id', $note->id)->count());
    }
}
