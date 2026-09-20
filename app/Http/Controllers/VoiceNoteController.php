<?php

namespace App\Http\Controllers;

use App\Enums\VoiceNoteStatus;
use App\Http\Requests\StoreVoiceNoteRequest;
use App\Http\Resources\VoiceNoteResource;
use App\Jobs\AnalyzeTranscript;
use App\Jobs\NormalizeAudio;
use App\Jobs\NotifyReady;
use App\Jobs\TranscribeAudio;
use App\Models\UsageLog;
use App\Models\VoiceNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class VoiceNoteController extends Controller
{
    /**
     * Returns immediately. Every slow step runs in the queued chain.
     */
    public function store(StoreVoiceNoteRequest $request): JsonResponse
    {
        $upload = $request->file('file');

        $note = VoiceNote::create([
            'user_id' => $request->user()?->id,
            'original_filename' => $upload->getClientOriginalName(),
            'storage_path' => $upload->store('voice-notes'),
            'duration_seconds' => $request->durationSeconds,
            'language_hint' => $request->input('language_hint'),
            'prompt_hint' => $request->input('prompt_hint'),
            'status' => VoiceNoteStatus::Pending,
        ]);

        /*
         * Opened here because ip_hash only exists in request context, closed out
         * by TranscribeAudio once the billable duration is known. One row per
         * note, which is also what Phase 6 will count for per-IP rate limiting.
         */
        UsageLog::create([
            'user_id' => $note->user_id,
            'ip_hash' => UsageLog::hashIp($request->ip()),
            'voice_note_id' => $note->id,
        ]);

        Bus::chain([
            new NormalizeAudio($note),
            new TranscribeAudio($note),
            new AnalyzeTranscript($note),
            new NotifyReady($note),
        ])->dispatch();

        return response()->json([
            'token' => $note->public_token,
            'status' => $note->status->value,
        ], 201);
    }

    public function show(VoiceNote $voiceNote): VoiceNoteResource|JsonResponse
    {
        if ($voiceNote->isExpired()) {
            return $this->gone();
        }

        return new VoiceNoteResource(
            $voiceNote->load(['transcript', 'digest'])
        );
    }

    /**
     * 410 rather than 404: the link was real, it has simply passed its
     * retention date. Saying so is more useful than pretending it never
     * existed, and it carries no content.
     */
    private function gone(): JsonResponse
    {
        return response()->json([
            'status' => 'expired',
            'message' => sprintf(
                'This voice note has passed its %d day retention window and has been deleted.',
                config('shoelzbde.retention_days'),
            ),
        ], 410);
    }

    /**
     * The original upload, served for the player on the result page.
     *
     * Inline rather than as a download, and via response()->file() so Symfony
     * answers Range requests - without that the player cannot seek and has to
     * pull the whole file before it will scrub, which is painful on a 20 minute
     * note over a phone connection.
     */
    public function audio(VoiceNote $voiceNote): BinaryFileResponse
    {
        abort_if($voiceNote->isExpired(), 410);
        abort_unless(Storage::exists($voiceNote->storage_path), 404);

        return response()->file(Storage::path($voiceNote->storage_path), [
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $voiceNote->original_filename,
                // ASCII fallback for clients that cannot read the UTF-8 form.
                'audio'.pathinfo($voiceNote->original_filename, PATHINFO_EXTENSION),
            ),
        ]);
    }
}
