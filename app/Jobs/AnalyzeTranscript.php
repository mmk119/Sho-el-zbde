<?php

namespace App\Jobs;

use App\Contracts\AnalysisService;
use App\Exceptions\AnalysisRateLimited;
use App\Exceptions\AnalysisRejected;
use App\Jobs\Concerns\TracksVoiceNoteProgress;
use App\Models\Digest;
use App\Models\UsageLog;
use App\Models\VoiceNote;
use App\Services\Analysis\AnalysisResult;
use App\Services\Analysis\DigestSchema;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns the transcript into the digest.
 *
 * Two different kinds of retry live here and they are deliberately separate:
 *
 *   Transport retries are the queue's job, on the same discipline as Phase 2 -
 *   a 30 minute window, three real exceptions, and a 429 releases rather than
 *   throws so being told to wait costs nothing.
 *
 *   Schema retries happen inside a single run. Model output that parses but
 *   does not match the schema is not a transport problem, so bouncing the whole
 *   job would re-send the transcript and pay for it twice for no reason. One
 *   stricter re-ask, then give up.
 *
 * Leaves the note in `analyzing`; NotifyReady is what marks it done.
 */
class AnalyzeTranscript implements ShouldQueue
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

    public function handle(AnalysisService $analyst): void
    {
        $note = $this->voiceNote->fresh();

        if ($note === null || $this->shouldSkip($note)) {
            return;
        }

        $transcript = $note->transcript;

        if ($transcript === null || trim($transcript->full_text) === '') {
            $note->markFailed($this->failureMessage());

            return;
        }

        try {
            [$payload, $result, $spentUsd, $spentTokens] = $this->askTwiceAtMost(
                $analyst,
                $transcript->full_text,
                $note->language_detected,
            );
        } catch (AnalysisRateLimited $e) {
            $this->release($e->retryAfterSeconds);

            return;
        } catch (AnalysisRejected $e) {
            $note->markFailed($this->failureMessage());
            $this->fail($e);

            return;
        }

        // We pay for rejected attempts too, so the cost is recorded either way.
        $this->recordUsage($note, $spentUsd, $spentTokens);

        if ($payload === null) {
            $note->markFailed(
                'We transcribed this note but could not summarise it reliably. The transcript is still available.'
            );

            return;
        }

        $this->store($note, $payload, $result, $spentTokens);
    }

    /**
     * One normal attempt, then at most one stricter re-ask on a schema failure.
     *
     * @return array{0: ?array, 1: ?AnalysisResult, 2: float, 3: int}
     */
    private function askTwiceAtMost(AnalysisService $analyst, string $transcript, ?string $language): array
    {
        $spentUsd = 0.0;
        $spentTokens = 0;
        $last = null;

        foreach ([false, true] as $stricter) {
            $result = $analyst->analyze($transcript, $language, $stricter);
            $last = $result;

            $spentUsd += $result->costUsd();
            $spentTokens += $result->totalTokens();

            if (! $result->wasUnparseable && DigestSchema::isValid($result->payload)) {
                return [$result->payload, $result, $spentUsd, $spentTokens];
            }
        }

        return [null, $last, $spentUsd, $spentTokens];
    }

    private function store(VoiceNote $note, array $payload, AnalysisResult $result, int $tokens): void
    {
        // normalize() drops anything the model invented beyond the schema.
        $digest = DigestSchema::normalize($payload);

        Digest::updateOrCreate(
            ['voice_note_id' => $note->id],
            $digest + [
                'model_used' => $result->model,
                'tokens_used' => $tokens,
            ],
        );
    }

    private function recordUsage(VoiceNote $note, float $usd, int $tokens): void
    {
        $log = UsageLog::firstOrNew(['voice_note_id' => $note->id]);

        $log->user_id ??= $note->user_id;
        $log->analysis_cost = $usd;
        $log->analysis_tokens = $tokens;
        $log->cost_estimate = round((float) ($log->transcription_cost ?? 0) + $usd, 6);
        $log->save();
    }

    protected function failureMessage(): string
    {
        return 'We transcribed this note but could not summarise it. The transcript is still available.';
    }
}
