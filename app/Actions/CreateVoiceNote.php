<?php

namespace App\Actions;

use App\Enums\VoiceNoteStatus;
use App\Jobs\AnalyzeTranscript;
use App\Jobs\NormalizeAudio;
use App\Jobs\NotifyReady;
use App\Jobs\TranscribeAudio;
use App\Models\UsageLog;
use App\Models\VoiceNote;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;

/**
 * The single place a voice note enters the system.
 *
 * Both entry points - the JSON API and the Livewire upload form - go through
 * here, so the two cannot drift on what gets stored, what gets logged, or what
 * gets dispatched. Validation stays with each caller, because each needs to
 * report failure differently.
 */
class CreateVoiceNote
{
    public function __invoke(
        UploadedFile $file,
        ?int $durationSeconds,
        ?string $languageHint = null,
        ?string $promptHint = null,
        ?int $userId = null,
        ?string $ip = null,
    ): VoiceNote {
        $note = VoiceNote::create([
            'user_id' => $userId,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $file->store('voice-notes'),
            'duration_seconds' => $durationSeconds,
            'language_hint' => $languageHint ?: null,
            'prompt_hint' => $promptHint ?: null,
            'status' => VoiceNoteStatus::Pending,
        ]);

        // Opened here because ip_hash only exists in request context; closed out
        // by TranscribeAudio once the billable duration is known.
        UsageLog::create([
            'user_id' => $userId,
            'ip_hash' => UsageLog::hashIp($ip),
            'voice_note_id' => $note->id,
        ]);

        Bus::chain([
            new NormalizeAudio($note),
            new TranscribeAudio($note),
            new AnalyzeTranscript($note),
            new NotifyReady($note),
        ])->dispatch();

        return $note;
    }
}
