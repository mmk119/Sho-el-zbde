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

        /*
         * Already transcribed. The normalized wav is deleted on success, so a
         * re-run must not mistake "the file is gone" for "the file is missing"
         * and fail a note whose transcript is sitting right there.
         */
        if ($note->transcript !== null) {
            $this->advance($note, VoiceNoteStatus::Analyzing);

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

        $this->discardNormalizedAudio($note);

        $this->advance($note, VoiceNoteStatus::Analyzing);
    }

    /**
     * The normalized wav has done its job the moment we have the text, and it
     * is the larger of the two files - uncompressed 16kHz PCM against a
     * compressed original. The original stays: it is what the result page plays
     * back and what duration_seconds was measured against.
     *
     * normalized_duration_seconds is untouched, so the billing number survives
     * the file it was measured from.
     */
    private function discardNormalizedAudio(VoiceNote $note): void
    {
        $path = $note->normalized_storage_path;

        if ($path === null) {
            return;
        }

        if (Storage::exists($path)) {
            Storage::delete($path);
        }

        $note->forceFill(['normalized_storage_path' => null])->save();
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

        $cost = round(($seconds / 60) * $perMinute, 6);

        $log = UsageLog::firstOrNew(['voice_note_id' => $note->id]);

        $log->user_id ??= $note->user_id;
        $log->duration_seconds = $seconds;
        $log->transcription_cost = $cost;
        // cost_estimate is the running total across steps; analysis adds to it.
        $log->cost_estimate = round($cost + (float) ($log->analysis_cost ?? 0), 6);
        $log->save();
    }

    protected function failureMessage(): string
    {
        return 'We could not transcribe this voice note. Please try again in a few minutes.';
    }
}
