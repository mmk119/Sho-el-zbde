<?php

namespace App\Services\Transcription;

use Illuminate\Support\Str;

/**
 * Builds the prompt that every transcription call must carry.
 *
 * Phase 0 measured the difference: unprompted, the same audio gained a phantom
 * opening phrase and leaked a Greek letter and an English word into Arabic
 * text. Prompted, it did neither. So this class has no code path that returns
 * an empty string - if config is somehow blank and the user supplied nothing,
 * the app name alone still goes out.
 */
final class TranscriptionPrompt
{
    public const FALLBACK = 'Sho el Zbde';

    public static function build(?string $userHint = null): string
    {
        $parts = array_filter([
            trim((string) config('shoelzbde.transcription.prompt')),
            trim((string) $userHint),
        ]);

        $prompt = trim(implode(', ', $parts));

        // Deliberately unreachable in normal operation - the config default is
        // never empty. It exists so that a misconfigured deployment degrades to
        // a weak prompt rather than to the unprompted path we know is worse.
        if ($prompt === '') {
            return self::FALLBACK;
        }

        // Whisper ignores prompts past roughly 224 tokens. Trim from the end so
        // the configured dialect vocabulary survives and a long user hint is
        // what gets cut.
        return Str::limit($prompt, 900, '');
    }
}
