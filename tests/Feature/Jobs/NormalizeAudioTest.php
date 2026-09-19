<?php

namespace Tests\Feature\Jobs;

use App\Enums\VoiceNoteStatus;
use App\Jobs\NormalizeAudio;
use App\Models\VoiceNote;

class NormalizeAudioTest extends JobChainTestCase
{
    protected function jobClass(): string
    {
        return NormalizeAudio::class;
    }

    protected function startingStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Pending;
    }

    protected function expectedStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Transcribing;
    }

    /**
     * Phase 1 must not invent a billing number. normalized_duration_seconds is
     * what usage_logs charges against, so it stays null until Phase 2 actually
     * runs ffmpeg and measures it.
     */
    public function test_it_does_not_fabricate_a_normalized_duration(): void
    {
        $note = VoiceNote::factory()->create([
            'duration_seconds' => 540,
            'normalized_duration_seconds' => null,
        ]);

        (new NormalizeAudio($note))->handle();

        $this->assertNull($note->fresh()->normalized_duration_seconds);
    }
}
