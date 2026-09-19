<?php

namespace App\Jobs;

use App\Enums\VoiceNoteStatus;
use App\Jobs\Concerns\TracksVoiceNoteProgress;
use App\Models\VoiceNote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Phase 1 stub. The last link in the chain: this is what flips a note to done.
 * Phase 5 adds the progress notification; the GET endpoint is what the frontend
 * polls until then.
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

        sleep(2);

        $this->advance($note, VoiceNoteStatus::Done);
    }

    protected function failureMessage(): string
    {
        return 'Your digest is ready but we could not send the notification.';
    }
}
