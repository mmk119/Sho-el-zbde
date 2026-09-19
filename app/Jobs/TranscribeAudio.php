<?php

namespace App\Jobs;

use App\Enums\VoiceNoteStatus;
use App\Jobs\Concerns\TracksVoiceNoteProgress;
use App\Models\VoiceNote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Phase 1 stub. Phase 2 replaces the sleep with a TranscriptionService call.
 *
 * Phase 0 finding: that call must ALWAYS send a prompt. The unprompted run
 * hallucinated an opening phrase and leaked non-Arabic characters into Arabic
 * text. There is to be no "no prompt" code path.
 */
class TranscribeAudio implements ShouldQueue
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

        $this->advance($note, VoiceNoteStatus::Analyzing);
    }

    protected function failureMessage(): string
    {
        return 'We could not transcribe this voice note. Please try again in a few minutes.';
    }
}
