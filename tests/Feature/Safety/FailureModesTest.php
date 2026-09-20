<?php

namespace Tests\Feature\Safety;

use App\Contracts\AnalysisService;
use App\Contracts\AudioNormalizer;
use App\Contracts\TranscriptionService;
use App\Enums\VoiceNoteStatus;
use App\Exceptions\AudioNormalizationFailed;
use App\Exceptions\TranscriptionRejected;
use App\Jobs\AnalyzeTranscript;
use App\Jobs\NormalizeAudio;
use App\Jobs\TranscribeAudio;
use App\Models\Transcript;
use App\Models\VoiceNote;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAnalysisService;
use Tests\Support\FakeAudioNormalizer;
use Tests\Support\FakeTranscriptionService;
use Tests\TestCase;

/**
 * The three ways a note can die, each driven through the real job and then read
 * back off the result page.
 *
 * The thing being asserted is the same every time: the reader gets a sentence
 * that tells them what happened, never a blank digest and never exception text.
 */
class FailureModesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function page(VoiceNote $note)
    {
        return $this->get(route('notes.show', $note->public_token));
    }

    // ── 1. Bad audio ────────────────────────────────────────────────────────

    public function test_bad_audio_explains_itself_on_the_page(): void
    {
        $this->app->bind(AudioNormalizer::class, fn () => new FakeAudioNormalizer(shouldFail: true));

        $note = VoiceNote::factory()->create(['original_filename' => 'broken.m4a']);

        try {
            $this->app->call([new NormalizeAudio($note), 'handle']);
        } catch (AudioNormalizationFailed) {
            // rethrown on purpose so the queue records the real cause
        }

        $this->assertSame(VoiceNoteStatus::Failed, $note->fresh()->status);

        $this->page($note->fresh())
            ->assertOk()
            ->assertSee("This one didn't work out", false)
            ->assertSee('may be corrupt or in an unsupported format');
    }

    public function test_bad_audio_does_not_leak_ffmpeg_internals(): void
    {
        $this->app->bind(AudioNormalizer::class, fn () => new FakeAudioNormalizer(shouldFail: true));

        $note = VoiceNote::factory()->create();

        try {
            $this->app->call([new NormalizeAudio($note), 'handle']);
        } catch (AudioNormalizationFailed) {
        }

        $this->page($note->fresh())
            ->assertDontSee('ffmpeg')
            ->assertDontSee('Exception');
    }

    // ── 2. Transcription failure ────────────────────────────────────────────

    public function test_a_transcription_failure_explains_itself_on_the_page(): void
    {
        $this->app->bind(TranscriptionService::class, fn () => new FakeTranscriptionService(
            throws: new TranscriptionRejected('HTTP 401 invalid_api_key'),
        ));

        $note = VoiceNote::factory()->status(VoiceNoteStatus::Transcribing)->create([
            'normalized_storage_path' => 'voice-notes/normalized/n.wav',
        ]);
        Storage::put('voice-notes/normalized/n.wav', 'bytes');

        $job = new TranscribeAudio($note->fresh());
        $job->job = $this->createMock(Job::class);
        $this->app->call([$job, 'handle']);

        $this->assertSame(VoiceNoteStatus::Failed, $note->fresh()->status);

        $this->page($note->fresh())
            ->assertOk()
            ->assertSee('could not transcribe this voice note')
            ->assertDontSee('invalid_api_key')
            ->assertDontSee('401');
    }

    public function test_a_missing_normalized_file_fails_readably_rather_than_blankly(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Transcribing)->create([
            'normalized_storage_path' => null,
        ]);

        $this->app->bind(TranscriptionService::class, fn () => new FakeTranscriptionService());
        $this->app->call([new TranscribeAudio($note), 'handle']);

        $this->page($note->fresh())
            ->assertSee('could not transcribe this voice note');
    }

    // ── 3. Analysis schema failure ──────────────────────────────────────────

    public function test_an_analysis_schema_failure_keeps_the_transcript_and_says_so(): void
    {
        // Two malformed responses: the normal pass and the stricter retry.
        $this->app->bind(AnalysisService::class, fn () => new FakeAnalysisService([
            FakeAnalysisService::result(['summary' => ['missing every other key']]),
            FakeAnalysisService::result(['summary' => ['still missing them']]),
        ]));

        $note = VoiceNote::factory()->status(VoiceNoteStatus::Analyzing)->create([
            'language_detected' => 'arabic',
        ]);

        Transcript::create([
            'voice_note_id' => $note->id,
            'full_text' => 'انا عشت الحرب بلبنان كلها',
            'word_count' => 5,
        ]);

        $this->app->call([new AnalyzeTranscript($note->fresh()), 'handle']);

        $fresh = $note->fresh();

        $this->assertSame(VoiceNoteStatus::Failed, $fresh->status);
        $this->assertNull($fresh->digest, 'an unvalidated digest must never be stored');

        $this->page($fresh)
            ->assertOk()
            ->assertSee('could not summarise it reliably')
            // The work that did succeed is still worth something.
            ->assertSee('انا عشت الحرب بلبنان كلها', false);
    }

    public function test_a_failed_note_never_renders_an_empty_digest_shell(): void
    {
        $this->app->bind(AnalysisService::class, fn () => new FakeAnalysisService([
            FakeAnalysisService::result(null, unparseable: true),
            FakeAnalysisService::result(null, unparseable: true),
        ]));

        $note = VoiceNote::factory()->status(VoiceNoteStatus::Analyzing)->create();
        Transcript::create(['voice_note_id' => $note->id, 'full_text' => 'نص', 'word_count' => 1]);

        $this->app->call([new AnalyzeTranscript($note->fresh()), 'handle']);

        $this->page($note->fresh())
            ->assertDontSee('El Zbde')
            ->assertDontSee('Questions for you')
            ->assertDontSee('Key details');
    }

    /** Every failure path must leave a sentence behind, whatever went wrong. */
    public function test_no_failure_path_leaves_the_reason_blank(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Analyzing)->create();

        // No transcript at all - the degenerate case.
        $this->app->bind(AnalysisService::class, fn () => new FakeAnalysisService());
        $this->app->call([new AnalyzeTranscript($note), 'handle']);

        $this->assertNotEmpty($note->fresh()->error_message);
    }
}
