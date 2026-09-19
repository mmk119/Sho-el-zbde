<?php

namespace App\Models;

use App\Enums\VoiceNoteStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class VoiceNote extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => VoiceNoteStatus::class,
            'expires_at' => 'datetime',
            'duration_seconds' => 'integer',
            'normalized_duration_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VoiceNote $note) {
            $note->public_token ??= static::newToken();
            $note->expires_at ??= now()->addDays(config('shoelzbde.retention_days'));
        });
    }

    /**
     * 40 hex characters from a CSPRNG. The share URL is the only thing standing
     * between a private message and the internet, so this must not be guessable
     * and must not be derived from anything about the note.
     */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    public function getRouteKeyName(): string
    {
        return 'public_token';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transcript(): HasOne
    {
        return $this->hasOne(Transcript::class);
    }

    public function digest(): HasOne
    {
        return $this->hasOne(Digest::class);
    }

    /**
     * Single funnel for status changes so no job invents its own transition and
     * nothing reopens a note that already finished or failed.
     */
    public function markStatus(VoiceNoteStatus $status, ?string $error = null): void
    {
        $this->forceFill([
            'status' => $status,
            'error_message' => $error,
        ])->save();
    }

    public function markFailed(string $readableError): void
    {
        $this->markStatus(VoiceNoteStatus::Failed, $readableError);
    }
}
