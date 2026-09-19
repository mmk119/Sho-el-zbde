<?php

namespace Tests\Feature\Jobs;

use App\Enums\VoiceNoteStatus;
use App\Jobs\AnalyzeTranscript;

class AnalyzeTranscriptTest extends JobChainTestCase
{
    protected function jobClass(): string
    {
        return AnalyzeTranscript::class;
    }

    protected function startingStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Analyzing;
    }

    /** This job does its work but leaves the note analyzing; NotifyReady closes it. */
    protected function expectedStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Analyzing;
    }
}
