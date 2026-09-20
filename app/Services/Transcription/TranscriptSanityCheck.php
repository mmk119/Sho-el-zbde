<?php

namespace App\Services\Transcription;

/**
 * Refuses a transcript that is obviously not a transcription of the audio.
 *
 * Whisper fails loudly enough to notice but quietly enough to store: a prompt
 * echo comes back as valid, well-formed text of the right language, and passes
 * every structural check there is. The only things that give it away are that
 * it is far too short for the audio, and that it is largely made of the prompt.
 *
 * Both are checked here, and a failure stops the transcript being written at
 * all. A wrong transcript is worse than a failed one: the digest built on it
 * looks confident and is entirely fictional.
 */
final class TranscriptSanityCheck
{
    /**
     * @return string|null a readable problem, or null when the transcript looks real
     */
    public static function problem(string $transcript, string $prompt, ?int $audioSeconds): ?string
    {
        $words = self::tokenise($transcript);

        if ($words === []) {
            return 'The transcription came back empty.';
        }

        if ($tooSparse = self::tooSparse($words, $audioSeconds)) {
            return $tooSparse;
        }

        return self::echoesPrompt($words, $prompt);
    }

    /**
     * Speech runs 100-150 words a minute; even a slow, halting speaker clears
     * 60. The floor is set far below that - a quarter of slow speech - so it
     * only ever catches a genuine collapse, not an unusually quiet note.
     *
     * Short clips are exempt: a five second "call me back" is legitimately a
     * handful of words, and the rate is meaningless over so little audio.
     *
     * @param string[] $words
     */
    private static function tooSparse(array $words, ?int $audioSeconds): ?string
    {
        $from = (int) config('shoelzbde.transcription.sanity_check_from_seconds', 30);

        if ($audioSeconds === null || $audioSeconds < $from) {
            return null;
        }

        $floor = (int) config('shoelzbde.transcription.min_words_per_minute', 25);
        $rate = count($words) / ($audioSeconds / 60);

        if ($rate >= $floor) {
            return null;
        }

        return sprintf(
            'The transcription produced only %d words for %d seconds of speech (%.0f a minute), '
            .'which is too sparse to be a real transcript.',
            count($words),
            $audioSeconds,
            $rate,
        );
    }

    /**
     * A prompt echo is mostly prompt. A real transcript shares only the common
     * words any two samples of a language share, which in practice sits well
     * under half even when the prompt is dialect vocabulary chosen to appear in
     * the speech.
     *
     * @param string[] $words
     */
    private static function echoesPrompt(array $words, string $prompt): ?string
    {
        $promptWords = array_flip(self::tokenise($prompt));

        if ($promptWords === []) {
            return null;
        }

        $fromPrompt = 0;

        foreach ($words as $word) {
            if (isset($promptWords[$word])) {
                $fromPrompt++;
            }
        }

        $share = $fromPrompt / count($words);
        $limit = (float) config('shoelzbde.transcription.max_prompt_overlap', 0.6);

        if ($share < $limit) {
            return null;
        }

        return sprintf(
            'The transcription is %.0f%% words taken from the prompt, so it repeated the prompt '
            .'back rather than transcribing the audio.',
            $share * 100,
        );
    }

    /**
     * @return string[] lowercased words, punctuation dropped, Unicode aware so
     *                  Arabic and Latin tokenise the same way
     */
    private static function tokenise(string $text): array
    {
        $parts = preg_split('/[\s\p{P}\p{S}]+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? $parts : [];
    }
}
