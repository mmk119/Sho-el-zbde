<?php

namespace App\Services\Transcription;

use Illuminate\Support\Str;

/**
 * Builds the prompt that every transcription call must carry.
 *
 * Two findings shape this class, and they pull against each other:
 *
 *   Phase 0: the prompt is load-bearing. Unprompted, the same Lebanese audio
 *   gained a phantom opening phrase and leaked a Greek letter and an English
 *   word into Arabic text. So the prompt is never empty.
 *
 *   Found later, by uploading an English voice note: a Whisper prompt is also a
 *   LANGUAGE signal. An Arabic-language prompt made Whisper translate English
 *   speech into Arabic - a correct transcription of the wrong language. The
 *   dialect vocabulary that rescues Lebanese audio actively destroys everything
 *   else.
 *
 * So the prompt is chosen by language rather than being one global string. When
 * the uploader picks a language we use that language's prompt; when they leave
 * it on auto-detect we use a short, script-neutral one, because at that point
 * anything longer is just guessing at the answer.
 */
final class TranscriptionPrompt
{
    public const FALLBACK = 'Sho el Zbde';

    public static function build(?string $languageHint = null, ?string $userHint = null): string
    {
        $parts = array_filter([
            self::base($languageHint),
            trim((string) $userHint),
        ]);

        $prompt = trim(implode(' ', $parts));

        // Deliberately unreachable in normal operation - the config default is
        // never empty. It exists so that a misconfigured deployment degrades to
        // a weak prompt rather than to the unprompted path we know is worse.
        if ($prompt === '') {
            return self::FALLBACK;
        }

        // Whisper ignores prompts past roughly 224 tokens. Trim from the end so
        // the configured vocabulary survives and a long user hint is what gets
        // cut.
        return Str::limit($prompt, 900, '');
    }

    /**
     * The language-appropriate base prompt. Falls back to the neutral one for
     * auto-detect and for any language we have no specific vocabulary for.
     */
    private static function base(?string $languageHint): string
    {
        $prompts = (array) config('shoelzbde.transcription.prompts', []);
        $key = strtolower(trim((string) $languageHint));

        $chosen = $key !== '' && isset($prompts[$key])
            ? $prompts[$key]
            : ($prompts['default'] ?? '');

        return trim((string) $chosen);
    }
}
