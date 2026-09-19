<?php

namespace App\Jobs;

use App\Contracts\AudioNormalizer;
use App\Enums\VoiceNoteStatus;
use App\Exceptions\AudioNormalizationFailed;
use App\Jobs\Concerns\TracksVoiceNoteProgress;
use App\Models\VoiceNote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Converts the upload to mono 16kHz pcm_s16le and trims long silences, using
 * the same ffmpeg filter chain transcribe-test.php used in Phase 0.
 */
class NormalizeAudio implements ShouldQueue
{
    use Queueable;
    use TracksVoiceNoteProgress;

    public int $tries = 3;

    public array $backoff = [5, 20];

    public function __construct(public VoiceNote $voiceNote)
    {
    }

    public function handle(AudioNormalizer $normalizer): void
    {
        $note = $this->voiceNote->fresh();

        if ($note === null || $this->shouldSkip($note)) {
            return;
        }

        $target = 'voice-notes/normalized/'.$note->public_token.'.wav';

        try {
            $normalized = $normalizer->normalize(
                Storage::path($note->storage_path),
                Storage::path($target),
            );
        } catch (AudioNormalizationFailed $e) {
            $note->markFailed($this->failureMessage());

            // Rethrow so the queue records the real cause for the operator while
            // the user sees only the sentence above.
            throw $e;
        }

        $note->forceFill([
            'normalized_storage_path' => $target,
            // The real measured number this time - this is what gets sent for
            // transcription and therefore what we are billed on.
            'normalized_duration_seconds' => $normalized->durationSeconds,
        ])->save();

        $this->advance($note, VoiceNoteStatus::Transcribing);
    }

    protected function failureMessage(): string
    {
        return 'We could not process this audio file. It may be corrupt or in an unsupported format.';
    }
}
