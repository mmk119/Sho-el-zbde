<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transcript extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'word_count' => 'integer',
        ];
    }

    public function voiceNote(): BelongsTo
    {
        return $this->belongsTo(VoiceNote::class);
    }
}
