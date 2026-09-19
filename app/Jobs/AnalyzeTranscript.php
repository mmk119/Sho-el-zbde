<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksVoiceNoteProgress;
use App\Models\VoiceNote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Phase 1 stub. Phase 3 replaces the sleep with an AnalysisService call whose
 * output is validated against a schema before storing, with one stricter retry
 * on a parse failure.
 *
 * Phase 0 finding: the analysis prompt must state that the transcript is machine
 * generated and may contain errors on proper nouns and code-switched words, and
 * must flag unclear passages rather than invent meaning.
 *
 * Leaves the note in `analyzing`; NotifyReady is what marks it done.
 */
class AnalyzeTranscript implements ShouldQueue
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
    }

    protected function failureMessage(): string
    {
        return 'We transcribed this note but could not summarise it. The transcript is still available.';
    }
}
