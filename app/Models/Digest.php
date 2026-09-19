<?php

namespace App\Models;

use App\Enums\Urgency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Digest extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'questions' => 'array',
            'entities' => 'array',
            'action_items' => 'array',
            'notes' => 'array',
            'urgency' => Urgency::class,
            'tokens_used' => 'integer',
        ];
    }

    public function voiceNote(): BelongsTo
    {
        return $this->belongsTo(VoiceNote::class);
    }
}
