<?php

namespace Tests\Feature\Jobs;

use App\Contracts\TranscriptionService;
use App\Enums\VoiceNoteStatus;
use App\Exceptions\TranscriptionRateLimited;
use App\Exceptions\TranscriptionRejected;
use App\Jobs\TranscribeAudio;
use App\Models\UsageLog;
use App\Models\VoiceNote;
use App\Services\Transcription\TranscriptionResult;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTranscriptionService;

class TranscribeAudioTest extends JobChainTestCase
{
    protected function jobClass(): string
    {
        return TranscribeAudio::class;
    }

    protected function startingStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Transcribing;
    }

    protected function expectedStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Analyzing;
    }

    /** This job will not run without a normalized file to send. */
    protected function prepare(VoiceNote $note): VoiceNote
    {
        $path = 'voice-notes/normalized/'.$note->public_token.'.wav';
        Storage::put($path, 'normalized bytes');

        $note->forceFill([
            'normalized_storage_path' => $path,
            'normalized_duration_seconds' => 461,
        ])->save();

        return $note;
    }

    public function test_it_stores_the_transcript_and_detected_language(): void
    {
        $note = $this->makeNote(VoiceNoteStatus::Transcribing);

        $this->runJob($note);

        $this->assertSame('arabic', $note->fresh()->language_detected);
        $this->assertSame('مرحبا كيفك', $note->fresh()->transcript->full_text);
        $this->assertSame(2, $note->fresh()->transcript->word_count);
    }

    /** The Phase 0 finding, enforced at the job level. */
    public function test_it_never_calls_out_without_a_prompt(): void
    {
        $fake = new FakeTranscriptionService();
        $this->app->bind(TranscriptionService::class, fn () => $fake);

        $note = $this->makeNote(VoiceNoteStatus::Transcribing);

        $this->runJob($note);

        $this->assertNotSame('', trim((string) $fake->sawPrompt));
        $this->assertStringContainsString('Sho el Zbde', $fake->sawPrompt);
    }

    public function test_it_appends_the_uploaders_names_to_the_prompt(): void
    {
        $fake = new FakeTranscriptionService();
        $this->app->bind(TranscriptionService::class, fn () => $fake);

        $note = $this->makeNote(VoiceNoteStatus::Transcribing, ['prompt_hint' => 'Teta Mariam, Jounieh']);

        $this->runJob($note);

        $this->assertStringContainsString('Teta Mariam', $fake->sawPrompt);
        $this->assertStringContainsString('Sho el Zbde', $fake->sawPrompt);
    }

    /**
     * Regression: an Arabic prompt made Whisper translate English speech into
     * Arabic. The note's language hint has to reach the prompt builder.
     */
    public function test_an_english_note_is_not_given_an_arabic_prompt(): void
    {
        $fake = new FakeTranscriptionService();
        $this->app->bind(TranscriptionService::class, fn () => $fake);

        $note = $this->makeNote(VoiceNoteStatus::Transcribing, ['language_hint' => 'en']);

        $this->runJob($note);

        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $fake->sawPrompt);
        $this->assertSame('en', $fake->sawLanguageHint);
    }

    public function test_it_bills_against_the_normalized_duration(): void
    {
        $note = $this->makeNote(VoiceNoteStatus::Transcribing);
        UsageLog::create(['voice_note_id' => $note->id, 'ip_hash' => 'abc']);

        $this->runJob($note);

        $log = UsageLog::where('voice_note_id', $note->id)->sole();

        $this->assertSame(461, $log->duration_seconds, 'must bill the normalized duration');
        $this->assertSame('abc', $log->ip_hash, 'the row opened at upload must be reused');
        $this->assertEqualsWithDelta(461 / 60 * 0.006, (float) $log->cost_estimate, 0.000001);
    }

    public function test_a_rate_limit_releases_instead_of_failing(): void
    {
        $this->app->bind(TranscriptionService::class, fn () => new FakeTranscriptionService(
            throws: new TranscriptionRateLimited(45),
        ));

        $note = $this->makeNote(VoiceNoteStatus::Transcribing);

        $job = new TranscribeAudio($note);
        $job->job = $this->createMock(\Illuminate\Contracts\Queue\Job::class);
        $job->job->expects($this->once())->method('release')->with(45);

        $this->app->call([$job, 'handle']);

        $this->assertSame(
            VoiceNoteStatus::Transcribing,
            $note->fresh()->status,
            'a 429 is not a failure and must not move the note',
        );
    }

    public function test_a_permanent_rejection_marks_the_note_failed(): void
    {
        $this->app->bind(TranscriptionService::class, fn () => new FakeTranscriptionService(
            throws: new TranscriptionRejected('bad key'),
        ));

        $note = $this->makeNote(VoiceNoteStatus::Transcribing);

        $job = new TranscribeAudio($note);
        $job->job = $this->createMock(\Illuminate\Contracts\Queue\Job::class);

        $this->app->call([$job, 'handle']);

        $fresh = $note->fresh();
        $this->assertSame(VoiceNoteStatus::Failed, $fresh->status);
        $this->assertStringNotContainsString('bad key', $fresh->error_message);
    }

    public function test_it_fails_readably_when_the_normalized_file_is_missing(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Transcribing)->create([
            'normalized_storage_path' => null,
        ]);

        $this->runJob($note);

        $this->assertSame(VoiceNoteStatus::Failed, $note->fresh()->status);
        $this->assertNotEmpty($note->fresh()->error_message);
    }

    public function test_it_stores_segments_but_the_app_does_not_depend_on_them(): void
    {
        $this->app->bind(TranscriptionService::class, fn () => new FakeTranscriptionService(
            result: new TranscriptionResult('نص', 'arabic', null, 100.0, 'whisper-1'),
        ));

        $note = $this->makeNote(VoiceNoteStatus::Transcribing);

        $this->runJob($note);

        // Null segments must not break anything downstream.
        $this->assertNull($note->fresh()->transcript->segments);
        $this->assertSame(VoiceNoteStatus::Analyzing, $note->fresh()->status);
    }
}
