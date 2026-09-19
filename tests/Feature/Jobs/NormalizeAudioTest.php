<?php

namespace Tests\Feature\Jobs;

use App\Contracts\AudioNormalizer;
use App\Enums\VoiceNoteStatus;
use App\Exceptions\AudioNormalizationFailed;
use App\Jobs\NormalizeAudio;
use App\Models\VoiceNote;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAudioNormalizer;

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
     * The whole point of the second duration column: it records what ffmpeg
     * actually produced, not what was uploaded. Silence trimming makes these
     * differ, and usage_logs bills against the normalized one.
     */
    public function test_it_records_the_measured_normalized_duration_not_the_original(): void
    {
        $this->app->bind(AudioNormalizer::class, fn () => new FakeAudioNormalizer(durationSeconds: 461));

        $note = VoiceNote::factory()->create(['duration_seconds' => 540]);

        $this->runJob($note);

        $fresh = $note->fresh();

        $this->assertSame(540, $fresh->duration_seconds, 'the original must not be overwritten');
        $this->assertSame(461, $fresh->normalized_duration_seconds);
    }

    public function test_it_records_where_the_normalized_file_landed(): void
    {
        $note = VoiceNote::factory()->create();

        $this->runJob($note);

        $this->assertSame(
            'voice-notes/normalized/'.$note->public_token.'.wav',
            $note->fresh()->normalized_storage_path,
        );
    }

    public function test_it_keeps_the_original_file_untouched(): void
    {
        $note = VoiceNote::factory()->create();
        Storage::put($note->storage_path, 'original bytes');

        $this->runJob($note);

        $this->assertSame('original bytes', Storage::get($note->storage_path));
    }

    public function test_a_normalization_failure_marks_the_note_failed_readably(): void
    {
        $this->app->bind(AudioNormalizer::class, fn () => new FakeAudioNormalizer(shouldFail: true));

        $note = VoiceNote::factory()->create();

        try {
            $this->runJob($note);
        } catch (AudioNormalizationFailed) {
            // rethrown on purpose so the queue records the real cause
        }

        $fresh = $note->fresh();

        $this->assertSame(VoiceNoteStatus::Failed, $fresh->status);
        $this->assertStringNotContainsString('ffmpeg', $fresh->error_message);
    }
}
