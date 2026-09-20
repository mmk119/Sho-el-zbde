<?php

namespace App\Services\Analysis;

/**
 * The system prompt for the analysis step.
 *
 * Shaped directly by the Phase 0 finding: the transcript handed to this model is
 * machine output, not a document. It contains real errors, concentrated exactly
 * where they do the most damage - proper nouns and code-switched words. A digest
 * that confidently reports a wrong name or a wrong amount is worse than one that
 * admits it could not tell, so the prompt has to say so explicitly and give the
 * model somewhere to put its uncertainty.
 */
final class AnalysisPrompt
{
    public static function system(?string $language = null): string
    {
        $urgencies = implode('|', DigestSchema::URGENCIES);
        $languageRule = self::languageRule($language);

        return <<<PROMPT
        You extract the useful part of a voice note.

        WHAT YOU ARE READING
        The text you are given is a MACHINE TRANSCRIPT produced by speech
        recognition from audio. It is not a written document and it is not
        reliable word for word. Expect errors, and expect them to cluster on:

          - proper nouns: people, places, organisations
          - code-switched words, where the speaker mixes languages
          - numbers said quickly

        Two real examples from this system's own testing, both Arabic:
        the word إقليمية ("regional") was transcribed as اخرمية, which is not a
        word; the phrase خطوط تماس ("contact lines", the front lines of a
        conflict) was transcribed as خطوط تماث. In both cases the intended
        meaning is recoverable from context by a reader who knows the subject.

        HOW TO HANDLE THAT
        Where a garbled word is recoverable from context, recover it and use the
        meaning you recovered. Do not draw attention to the correction.

        Where you genuinely cannot tell what was said, DO NOT GUESS. Put a short
        description of the unclear passage into "notes" and leave it out of the
        rest of the digest. Never invent a name, a date, or an amount to fill a
        gap. An honest gap is more useful than a confident error.

        LANGUAGE
        {$languageRule}
        Do not translate them into English or any other language. If the speaker
        mixed languages, mirror that mix. Entity values stay exactly as the
        speaker said them.

        This is not a stylistic preference. A summary translated out of the
        speaker's language is the wrong answer even when it is accurate.

        WHAT TO PRODUCE
        Reply with a single JSON object and nothing else. Every key below must be
        present on every response, even when the correct value is an empty array.

          summary       array of 3 to 5 short strings, the gist
          questions     array of strings: things the speaker asked the listener,
                        in the speaker's own words. Empty if they asked nothing.
          entities      object with exactly these five keys, each an array of
                        strings: dates, times, amounts, names, places
          action_items  array of strings: what the listener is being asked to do
          urgency       exactly one of: {$urgencies}
          notes         array of strings: passages you could not make out, or
                        where the transcript was too garbled to summarise
                        confidently. Empty array when the transcript was clean.

        An empty "notes" is a claim that you understood everything. Only make it
        when it is true.
        PROMPT;
    }

    /**
     * Naming the detected language explicitly, rather than relying on a general
     * "use the speaker's language" instruction. The general form was measured
     * producing English summaries of Arabic speech.
     */
    private static function languageRule(?string $language): string
    {
        if ($language === null || trim($language) === '') {
            return 'Write "summary" and "questions" in the SAME LANGUAGE the speaker used in the transcript.';
        }

        $language = trim($language);

        return sprintf(
            'The speaker is speaking %s. Write "summary" and "questions" in %s, '
            .'using the same words and register the speaker used.',
            $language,
            $language,
        );
    }

    /**
     * Second attempt, after the first response failed schema validation. Says
     * nothing about the content - only about the shape - so a retry cannot
     * quietly change the digest's meaning.
     */
    public static function stricterReminder(): string
    {
        $keys = implode(', ', ['summary', 'questions', 'entities', 'action_items', 'urgency', 'notes']);
        $entityKeys = implode(', ', DigestSchema::ENTITY_KEYS);
        $urgencies = implode(', ', DigestSchema::URGENCIES);

        return <<<PROMPT
        Your previous response did not match the required format.

        Reply with ONE JSON object and absolutely nothing else: no prose before
        or after it, no markdown code fences, no explanation.

        The object must contain exactly these top level keys: {$keys}.

        - summary, questions, action_items and notes are arrays of strings. Use
          an empty array [] when there is nothing to report. Never omit a key
          and never use null.
        - entities is an object containing exactly these keys, each an array of
          strings: {$entityKeys}.
        - urgency is one of these exact strings: {$urgencies}.

        Do not change your findings. Only fix the shape.
        PROMPT;
    }

    public static function userMessage(string $transcript): string
    {
        return "Here is the machine transcript of the voice note:\n\n".$transcript;
    }
}
