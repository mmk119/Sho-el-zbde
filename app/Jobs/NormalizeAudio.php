<?php

namespace App\Jobs;

use App\Enums\VoiceNoteStatus;
use App\Jobs\Concerns\TracksVoiceNoteProgress;
use App\Models\VoiceNote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Phase 1 stub. Phase 2 replaces the sleep with ffmpeg: convert to mono 16kHz
 * wav, trim long silences, then record normalized_duration_seconds.
 */
class NormalizeAudio implements ShouldQueue
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

        // normalized_duration_seconds stays null until Phase 2 actually runs
        // ffmpeg. Writing the original duration here would be a fabricated
        // billing number, and usage_logs reads this column.
        $this->advance($note, VoiceNoteStatus::Transcribing);
    }

    protected function failureMessage(): string
    {
        return 'We could not process this audio file. It may be corrupt or in an unsupported format.';
    }
}
