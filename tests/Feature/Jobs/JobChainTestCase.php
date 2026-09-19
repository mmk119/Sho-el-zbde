<?php

namespace Tests\Feature\Jobs;

use App\Enums\VoiceNoteStatus;
use App\Models\VoiceNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Every job in the chain shares three behaviours, so they are asserted in one
 * place rather than copied four times:
 *
 *   1. it advances the note to its own next status
 *   2. it refuses to touch a note that already reached a terminal state
 *   3. failing writes a readable message, not an exception dump
 */
abstract class JobChainTestCase extends TestCase
{
    use RefreshDatabase;

    /** @return class-string */
    abstract protected function jobClass(): string;

    /** The status this job is expected to leave behind. */
    abstract protected function expectedStatus(): VoiceNoteStatus;

    /** The status the note is in when this job starts. */
    abstract protected function startingStatus(): VoiceNoteStatus;

    protected function makeJob(VoiceNote $note): object
    {
        $class = $this->jobClass();

        return new $class($note);
    }

    public function test_it_advances_the_note_to_its_next_status(): void
    {
        $note = VoiceNote::factory()->status($this->startingStatus())->create();

        $this->makeJob($note)->handle();

        $this->assertSame($this->expectedStatus(), $note->fresh()->status);
    }

    public function test_it_does_not_touch_a_note_that_already_failed(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Failed)->create([
            'error_message' => 'Something went wrong earlier.',
        ]);

        $this->makeJob($note)->handle();

        $fresh = $note->fresh();
        $this->assertSame(VoiceNoteStatus::Failed, $fresh->status);
        $this->assertSame('Something went wrong earlier.', $fresh->error_message);
    }

    public function test_it_does_not_reopen_a_finished_note(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Done)->create();

        $this->makeJob($note)->handle();

        $this->assertSame(VoiceNoteStatus::Done, $note->fresh()->status);
    }

    public function test_failing_writes_a_readable_error_and_no_exception_text(): void
    {
        $note = VoiceNote::factory()->status($this->startingStatus())->create();

        $this->makeJob($note)->failed(new RuntimeException('ffprobe: /var/secret/path exploded'));

        $fresh = $note->fresh();

        $this->assertSame(VoiceNoteStatus::Failed, $fresh->status);
        $this->assertNotEmpty($fresh->error_message);
        $this->assertStringNotContainsString('exploded', $fresh->error_message);
        $this->assertStringNotContainsString('/var/secret', $fresh->error_message);
    }
}
