<?php

namespace App\Jobs;

use App\Enums\VoiceNoteStatus;
use App\Jobs\Concerns\TracksVoiceNoteProgress;
use App\Models\VoiceNote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The last link in the chain, and the only job that marks a note done.
 *
 * It does no work: polling is how the reader finds out, so there is nothing to
 * send. It stays a separate job because "finished" is a real step that wants
 * its own status transition, and because anything that should happen on
 * completion - an email, a push - belongs here rather than bolted onto
 * AnalyzeTranscript.
 */
class NotifyReady implements ShouldQueue
{
    use Queueable;
    use TracksVoiceNoteProgress;

    public int $tries = 3;

    public function __construct(public VoiceNote $voiceNote)
    {
    }

    public function handle(): void
    {
        $note = $this->voiceNote->fresh();

        if ($note === null || $this->shouldSkip($note)) {
            return;
        }

        $this->advance($note, VoiceNoteStatus::Done);
    }

    protected function failureMessage(): string
    {
        return 'Your digest is ready but we could not send the notification.';
    }
}
