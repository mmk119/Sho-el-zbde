<?php

namespace App\Http\Resources;

use App\Models\VoiceNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VoiceNote */
class VoiceNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->public_token,
            'status' => $this->status->value,
            'error_message' => $this->error_message,
            'original_filename' => $this->original_filename,

            // The ORIGINAL duration - this is the number the user recognises.
            'duration_seconds' => $this->duration_seconds,

            'language_detected' => $this->language_detected,
            'audio_url' => route('voice-notes.audio', $this->public_token),
            'expires_at' => $this->expires_at?->toIso8601String(),

            'transcript' => $this->whenLoaded('transcript', fn () => [
                'full_text' => $this->transcript->full_text,
                'word_count' => $this->transcript->word_count,
                // Segment boundaries are not stable - display only, never
                // depended upon. See the Phase 0 findings in CLAUDE.md.
                'segments' => $this->transcript->segments,
            ]),

            'digest' => $this->whenLoaded('digest', fn () => [
                'summary' => $this->digest->summary,
                'questions' => $this->digest->questions,
                'entities' => $this->digest->entities,
                'action_items' => $this->digest->action_items,
                'urgency' => $this->digest->urgency->value,
            ]),
        ];
    }
}
