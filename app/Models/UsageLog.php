<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageLog extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'cost_estimate' => 'decimal:6',
            'transcription_cost' => 'decimal:6',
            'analysis_cost' => 'decimal:6',
            'analysis_tokens' => 'integer',
        ];
    }

    public function voiceNote(): BelongsTo
    {
        return $this->belongsTo(VoiceNote::class);
    }

    /** Never store a raw IP. */
    public static function hashIp(?string $ip): ?string
    {
        return $ip === null ? null : hash_hmac('sha256', $ip, config('app.key'));
    }
}
