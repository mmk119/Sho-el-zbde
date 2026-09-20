<?php

namespace App\Services\Transcription;

use Illuminate\Support\Str;

/**
 * Builds the prompt that every transcription call must carry.
 *
 * Three findings shape this class, each one paid for by running it:
 *
 *   Phase 0: the prompt is load-bearing. Unprompted, the same Lebanese audio
 *   gained a phantom opening phrase and leaked a Greek letter and an English
 *   word into Arabic text. So the prompt is never empty.
 *
 *   Found by uploading an English note: a Whisper prompt is also a LANGUAGE
 *   signal. An Arabic prompt made Whisper translate English speech into Arabic
 *   - a correct transcription of the wrong language. So prompts are keyed by
 *   language, and auto-detect gets a short script-neutral one.
 *
 *   Found through the MCP server, by passing names on an Arabic note: a Whisper
 *   prompt is a CONTINUATION, not an instruction. Whisper treats it as the
 *   transcript of audio immediately preceding, and carries on from it. The old
 *   prompt was a comma-separated glossary, so the model continued the glossary
 *   - emitting the word list over and over instead of transcribing. Adding
 *   names made it worse by lengthening the list.
 *
 * Hence the shape rule: the prompt must READ LIKE SPEECH. The vocabulary lives
 * inside ordinary sentences, and the uploader's names are folded into a
 * sentence too, never appended as another list item.
 */
final class TranscriptionPrompt
{
    public const FALLBACK = 'Sho el Zbde';

    public static function build(?string $languageHint = null, ?string $userHint = null): string
    {
        $parts = array_filter([
            self::base($languageHint),
            self::names($languageHint, $userHint),
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
        return trim((string) self::forLanguage('prompts', $languageHint));
    }

    /**
     * The uploader's names and places, folded into a sentence.
     *
     * Appending them bare would rebuild the list shape that caused the echo in
     * the first place, so they go through a per-language template. The default
     * template is bare by necessity: on auto-detect any framing words would
     * steer the output language before we know what it should be.
     */
    private static function names(?string $languageHint, ?string $userHint): string
    {
        $names = trim((string) $userHint);

        if ($names === '') {
            return '';
        }

        $template = trim((string) self::forLanguage('hint_templates', $languageHint));

        if ($template === '' || ! str_contains($template, ':names')) {
            return rtrim($names, " .،,").'.';
        }

        return str_replace(':names', rtrim($names, " .،,"), $template);
    }

    /** Look up a per-language config entry, falling back to 'default'. */
    private static function forLanguage(string $key, ?string $languageHint): string
    {
        $entries = (array) config("shoelzbde.transcription.{$key}", []);
        $code = strtolower(trim((string) $languageHint));

        if ($code !== '' && isset($entries[$code])) {
            return (string) $entries[$code];
        }

        return (string) ($entries['default'] ?? '');
    }
}
