<?php

namespace App\Jobs\Concerns;

use App\Enums\VoiceNoteStatus;
use App\Models\VoiceNote;
use Throwable;

/**
 * Shared behaviour for every job in the chain: refuse to touch a note that has
 * already finished or failed, and always leave a readable error behind when
 * something goes wrong.
 */
trait TracksVoiceNoteProgress
{
    /**
     * A human readable sentence shown to whoever is watching the result page.
     * Never leak exception text - it can contain file paths, and in later phases
     * API payloads derived from private audio.
     */
    abstract protected function failureMessage(): string;

    protected function shouldSkip(VoiceNote $note): bool
    {
        return $note->status->isTerminal();
    }

    public function failed(?Throwable $e): void
    {
        $note = $this->voiceNote->fresh();

        if ($note === null || $note->status->isTerminal()) {
            return;
        }

        $note->markFailed($this->failureMessage());
    }

    protected function advance(VoiceNote $note, VoiceNoteStatus $status): void
    {
        $note->markStatus($status);
    }
}
