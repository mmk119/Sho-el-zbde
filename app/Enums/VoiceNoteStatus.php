<?php

namespace App\Enums;

enum VoiceNoteStatus: string
{
    case Pending = 'pending';
    case Transcribing = 'transcribing';
    case Analyzing = 'analyzing';
    case Done = 'done';
    case Failed = 'failed';

    /** No job will move a note out of a terminal state. */
    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}
