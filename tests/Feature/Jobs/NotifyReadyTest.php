<?php

namespace Tests\Feature\Jobs;

use App\Enums\VoiceNoteStatus;
use App\Jobs\NotifyReady;

class NotifyReadyTest extends JobChainTestCase
{
    protected function jobClass(): string
    {
        return NotifyReady::class;
    }

    protected function startingStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Analyzing;
    }

    protected function expectedStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Done;
    }
}
