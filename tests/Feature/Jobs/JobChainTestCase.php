<?php

namespace Tests\Feature\Jobs;

use App\Contracts\AnalysisService;
use App\Contracts\AudioNormalizer;
use App\Contracts\TranscriptionService;
use App\Enums\VoiceNoteStatus;
use App\Models\VoiceNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakeAnalysisService;
use Tests\Support\FakeAudioNormalizer;
use Tests\Support\FakeTranscriptionService;
use Tests\TestCase;

/**
 * Every job in the chain shares three behaviours, so they are asserted in one
 * place rather than copied four times:
 *
 *   1. it advances the note to its own next status
 *   2. it refuses to touch a note that already reached a terminal state
 *   3. failing writes a readable message, not an exception dump
 *
 * No test in this hierarchy is allowed to reach a real network or a real
 * binary - the contracts are bound to fakes below.
 */
abstract class JobChainTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->app->bind(AudioNormalizer::class, fn () => new FakeAudioNormalizer());
        $this->app->bind(TranscriptionService::class, fn () => new FakeTranscriptionService());
        $this->app->bind(AnalysisService::class, fn () => new FakeAnalysisService());
    }

    /** @return class-string */
    abstract protected function jobClass(): string;

    /** The status this job is expected to leave behind. */
    abstract protected function expectedStatus(): VoiceNoteStatus;

    /** The status the note is in when this job starts. */
    abstract protected function startingStatus(): VoiceNoteStatus;

    /** Hook for jobs that need files or columns in place before they will run. */
    protected function prepare(VoiceNote $note): VoiceNote
    {
        return $note;
    }

    protected function makeNote(VoiceNoteStatus $status, array $attributes = []): VoiceNote
    {
        return $this->prepare(
            VoiceNote::factory()->status($status)->create($attributes)
        );
    }

    protected function makeJob(VoiceNote $note): object
    {
        $class = $this->jobClass();

        return new $class($note);
    }

    /** Resolve handle()'s dependencies from the container, as the queue does. */
    protected function runJob(VoiceNote $note): void
    {
        $this->app->call([$this->makeJob($note), 'handle']);
    }

    public function test_it_advances_the_note_to_its_next_status(): void
    {
        $note = $this->makeNote($this->startingStatus());

        $this->runJob($note);

        $this->assertSame($this->expectedStatus(), $note->fresh()->status);
    }

    public function test_it_does_not_touch_a_note_that_already_failed(): void
    {
        $note = $this->makeNote(VoiceNoteStatus::Failed, [
            'error_message' => 'Something went wrong earlier.',
        ]);

        $this->runJob($note);

        $fresh = $note->fresh();
        $this->assertSame(VoiceNoteStatus::Failed, $fresh->status);
        $this->assertSame('Something went wrong earlier.', $fresh->error_message);
    }

    public function test_it_does_not_reopen_a_finished_note(): void
    {
        $note = $this->makeNote(VoiceNoteStatus::Done);

        $this->runJob($note);

        $this->assertSame(VoiceNoteStatus::Done, $note->fresh()->status);
    }

    public function test_failing_writes_a_readable_error_and_no_exception_text(): void
    {
        $note = $this->makeNote($this->startingStatus());

        $this->makeJob($note)->failed(new RuntimeException('ffprobe: /var/secret/path exploded'));

        $fresh = $note->fresh();

        $this->assertSame(VoiceNoteStatus::Failed, $fresh->status);
        $this->assertNotEmpty($fresh->error_message);
        $this->assertStringNotContainsString('exploded', $fresh->error_message);
        $this->assertStringNotContainsString('/var/secret', $fresh->error_message);
    }
}
