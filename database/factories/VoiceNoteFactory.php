<?php

namespace Database\Factories;

use App\Enums\VoiceNoteStatus;
use App\Models\VoiceNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VoiceNote> */
class VoiceNoteFactory extends Factory
{
    protected $model = VoiceNote::class;

    public function definition(): array
    {
        return [
            'public_token' => VoiceNote::newToken(),
            'original_filename' => 'voice-note.m4a',
            'storage_path' => 'voice-notes/'.VoiceNote::newToken().'.m4a',
            'duration_seconds' => 540,
            'normalized_duration_seconds' => null,
            'language_hint' => null,
            'language_detected' => null,
            'status' => VoiceNoteStatus::Pending,
            'error_message' => null,
        ];
    }

    public function status(VoiceNoteStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
