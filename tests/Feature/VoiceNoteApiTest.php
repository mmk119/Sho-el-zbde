<?php

namespace Tests\Feature;

use App\Contracts\AudioInspector;
use App\Enums\VoiceNoteStatus;
use App\Jobs\AnalyzeTranscript;
use App\Jobs\NormalizeAudio;
use App\Jobs\NotifyReady;
use App\Jobs\TranscribeAudio;
use App\Models\Digest;
use App\Models\Transcript;
use App\Models\VoiceNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VoiceNoteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** Pretend ffprobe read a given duration, so tests never shell out. */
    protected function fakeDuration(?int $seconds): void
    {
        $this->mock(AudioInspector::class, function ($mock) use ($seconds) {
            $mock->shouldReceive('durationSeconds')->andReturn($seconds);
        });
    }

    protected function upload(int $sizeKb = 2048): UploadedFile
    {
        return UploadedFile::fake()->create('teta.m4a', $sizeKb, 'audio/x-m4a');
    }

    public function test_it_accepts_an_upload_and_returns_a_token_immediately(): void
    {
        Bus::fake();
        $this->fakeDuration(540);

        $response = $this->postJson(route('voice-notes.store'), [
            'file' => $this->upload(),
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'status'])
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseCount('voice_notes', 1);
    }

    public function test_it_dispatches_the_four_jobs_as_one_chain(): void
    {
        Bus::fake();
        $this->fakeDuration(540);

        $this->postJson(route('voice-notes.store'), ['file' => $this->upload()]);

        Bus::assertChained([
            NormalizeAudio::class,
            TranscribeAudio::class,
            AnalyzeTranscript::class,
            NotifyReady::class,
        ]);
    }

    public function test_it_stores_the_original_duration_not_the_normalized_one(): void
    {
        Bus::fake();
        $this->fakeDuration(540);

        $this->postJson(route('voice-notes.store'), ['file' => $this->upload()]);

        $note = VoiceNote::first();

        $this->assertSame(540, $note->duration_seconds);
        $this->assertNull($note->normalized_duration_seconds);
    }

    public function test_it_issues_an_unguessable_token(): void
    {
        Bus::fake();
        $this->fakeDuration(540);

        $this->postJson(route('voice-notes.store'), ['file' => $this->upload()]);

        $token = VoiceNote::first()->public_token;

        $this->assertSame(40, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $token);
    }

    public function test_it_rejects_audio_longer_than_the_limit_before_dispatching(): void
    {
        Bus::fake();

        // 21 minutes, one over the 20 minute limit.
        $this->fakeDuration(1260);

        $this->postJson(route('voice-notes.store'), ['file' => $this->upload()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Bus::assertNothingDispatched();
        $this->assertDatabaseCount('voice_notes', 0);
    }

    public function test_it_rejects_a_file_over_the_size_limit_before_dispatching(): void
    {
        Bus::fake();
        $this->fakeDuration(60);

        // 101MB against a 100MB limit.
        $this->postJson(route('voice-notes.store'), ['file' => $this->upload(101 * 1024)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Bus::assertNothingDispatched();
        $this->assertDatabaseCount('voice_notes', 0);
    }

    public function test_it_rejects_unreadable_audio(): void
    {
        Bus::fake();
        $this->fakeDuration(null);

        $this->postJson(route('voice-notes.store'), ['file' => $this->upload()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Bus::assertNothingDispatched();
    }

    public function test_it_returns_the_full_state_for_a_token(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Done)->create([
            'language_detected' => 'arabic',
        ]);

        Transcript::create([
            'voice_note_id' => $note->id,
            'full_text' => 'transcript body',
            'segments' => [['start' => 0, 'end' => 3, 'text' => 'hi']],
            'word_count' => 2,
        ]);

        Digest::create([
            'voice_note_id' => $note->id,
            'summary' => ['line one'],
            'questions' => ['can you call me?'],
            'entities' => ['dates' => ['Tuesday']],
            'action_items' => ['call back'],
            'urgency' => 'high',
        ]);

        $this->getJson(route('voice-notes.show', $note->public_token))
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.language_detected', 'arabic')
            ->assertJsonPath('data.duration_seconds', 540)
            ->assertJsonPath('data.digest.urgency', 'high')
            ->assertJsonPath('data.transcript.word_count', 2)
            ->assertJsonStructure(['data' => ['audio_url', 'error_message', 'original_filename']]);
    }

    public function test_it_reports_a_readable_error_for_a_failed_note(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Failed)->create([
            'error_message' => 'We could not transcribe this voice note.',
        ]);

        $this->getJson(route('voice-notes.show', $note->public_token))
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_message', 'We could not transcribe this voice note.');
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->getJson(route('voice-notes.show', str_repeat('a', 40)))
            ->assertNotFound();
    }
}
