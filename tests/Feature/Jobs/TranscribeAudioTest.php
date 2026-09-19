<?php

namespace Tests\Feature\Jobs;

use App\Enums\VoiceNoteStatus;
use App\Jobs\TranscribeAudio;

class TranscribeAudioTest extends JobChainTestCase
{
    protected function jobClass(): string
    {
        return TranscribeAudio::class;
    }

    protected function startingStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Transcribing;
    }

    protected function expectedStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Analyzing;
    }
}
