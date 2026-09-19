<?php

namespace App\Jobs;

use App\Contracts\TranscriptionService;
use App\Enums\VoiceNoteStatus;
use App\Exceptions\TranscriptionRateLimited;
use App\Exceptions\TranscriptionRejected;
use App\Jobs\Concerns\TracksVoiceNoteProgress;
use App\Models\Transcript;
use App\Models\UsageLog;
use App\Models\VoiceNote;
use App\Services\Transcription\TranscriptionPrompt;
use App\Services\Transcription\TranscriptionResult;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Retry policy, and why:
 *
 *   retryUntil()     A thirty minute window rather than a fixed attempt count.
 *                    Rate limiting is the expected failure here, and a window
 *                    lets the job keep waiting politely without a hard cap on
 *                    how many times it may be told to wait.
 *
 *   maxExceptions    Three. Real errors - timeouts, 5xx - fail fast rather than
 *                    grinding for the whole window. A 429 does not spend this
 *                    budget, because the job releases itself instead of
 *                    throwing.
 *
 *   backoff          10s, 30s, 60s between genuine errors.
 *
 *   429              Honour Retry-After, clamped to 1..300s, and release back
 *                    onto the queue. Not a failure.
 *
 *   4xx              TranscriptionRejected fails immediately. Retrying an
 *                    expired key or a malformed request for thirty minutes
 *                    helps nobody.
 */
class TranscribeAudio implements ShouldQueue
{
    use Queueable;
    use TracksVoiceNoteProgress;

    public int $maxExceptions = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public VoiceNote $voiceNote)
    {
    }

    public function retryUntil(): CarbonInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(TranscriptionService $transcriber): void
    {
        $note = $this->voiceNote->fresh();

        if ($note === null || $this->shouldSkip($note)) {
            return;
        }

        $path = $note->normalized_storage_path;

        if ($path === null || ! Storage::exists($path)) {
            $note->markFailed($this->failureMessage());

            return;
        }

        try {
            $result = $transcriber->transcribe(
                Storage::path($path),
                // Never empty. See TranscriptionPrompt and the Phase 0 findings.
                TranscriptionPrompt::build($note->prompt_hint),
                $note->language_hint,
            );
        } catch (TranscriptionRateLimited $e) {
            // Not a failure: wait as asked and try again inside the window.
            $this->release($e->retryAfterSeconds);

            return;
        } catch (TranscriptionRejected $e) {
            $note->markFailed($this->failureMessage());
            $this->fail($e);

            return;
        }

        $this->store($note, $result);

        $this->advance($note, VoiceNoteStatus::Analyzing);
    }

    private function store(VoiceNote $note, TranscriptionResult $result): void
    {
        Transcript::updateOrCreate(
            ['voice_note_id' => $note->id],
            [
                'full_text' => $result->text,
                'segments' => $result->segments,
                'word_count' => $result->wordCount(),
            ],
        );

        if ($result->language !== null) {
            $note->forceFill(['language_detected' => $result->language])->save();
        }

        $this->recordUsage($note);
    }

    /**
     * Bill against the normalized duration - what we sent, not what was
     * uploaded. The row was opened at upload time so it already carries the
     * ip_hash; this closes it out.
     */
    private function recordUsage(VoiceNote $note): void
    {
        $seconds = $note->fresh()->normalized_duration_seconds;

        if ($seconds === null) {
            return;
        }

        $perMinute = (float) config('shoelzbde.transcription.cost_per_minute_usd');

        UsageLog::updateOrCreate(
            ['voice_note_id' => $note->id],
            [
                'user_id' => $note->user_id,
                'duration_seconds' => $seconds,
                'cost_estimate' => round(($seconds / 60) * $perMinute, 6),
            ],
        );
    }

    protected function failureMessage(): string
    {
        return 'We could not transcribe this voice note. Please try again in a few minutes.';
    }
}
