import { CHARACTER_LIMIT } from "./constants.js";
import type { NotePayload } from "./types.js";

const ENTITY_LABELS: Record<string, string> = {
  dates: "Dates",
  times: "Times",
  amounts: "Amounts",
  names: "Names",
  places: "Places",
};

function duration(seconds: number | null): string | null {
  if (seconds === null) return null;
  const minutes = Math.floor(seconds / 60);
  return `${minutes}:${String(seconds % 60).padStart(2, "0")}`;
}

/** The one-line "still working" answer. Not an error - the pipeline is doing its job. */
export function describeInProgress(note: NotePayload): string {
  const stage: Record<string, string> = {
    pending: "queued, waiting for the audio to be prepared",
    transcribing: "being transcribed",
    analyzing: "being summarised",
  };

  return [
    `Not ready yet: "${note.original_filename}" is ${stage[note.status] ?? note.status}.`,
    ``,
    `This normally takes under a minute for a short note, longer for a long one.`,
    `Call get_digest again with the same token in a few seconds.`,
  ].join("\n");
}

export function describeFailed(note: NotePayload): string {
  const lines = [
    `"${note.original_filename}" could not be processed.`,
    ``,
    note.error_message ?? "No reason was recorded.",
  ];

  if (note.transcript?.full_text) {
    lines.push(
      ``,
      `A transcript was produced before it failed, so the recording itself was readable. ` +
        `Call get_digest again with include_transcript: true to read it.`,
    );
  }

  return lines.join("\n");
}

/**
 * The digest as prose.
 *
 * The transcript is left out unless explicitly asked for. It is the private
 * content of somebody's voice note, it is long, and the digest is the thing
 * that was actually wanted.
 */
export function describeDigest(note: NotePayload, includeTranscript: boolean): string {
  const digest = note.digest;
  if (!digest) {
    return `"${note.original_filename}" finished, but no digest was stored for it.`;
  }

  const meta = [
    note.language_detected ? note.language_detected : null,
    duration(note.duration_seconds),
    note.transcript ? `${note.transcript.word_count} words` : null,
  ].filter(Boolean);

  const lines: string[] = [
    `# ${note.original_filename}`,
    ``,
    `**Urgency: ${digest.urgency}**${meta.length ? ` · ${meta.join(" · ")}` : ""}`,
    ``,
    `## El Zbde`,
    ``,
    ...digest.summary.map((point) => `- ${point}`),
  ];

  lines.push(``, `## Questions for you`, ``);
  lines.push(
    digest.questions.length
      ? digest.questions.map((question) => `- ${question}`).join("\n")
      : `_Nothing to answer. They didn't ask you anything directly._`,
  );

  const entities = Object.entries(digest.entities ?? {}).filter(
    ([, values]) => Array.isArray(values) && values.length > 0,
  );

  if (entities.length) {
    lines.push(``, `## Key details`, ``);
    for (const [type, values] of entities) {
      lines.push(`- **${ENTITY_LABELS[type] ?? type}:** ${(values as string[]).join(", ")}`);
    }
  }

  if (digest.action_items.length) {
    lines.push(``, `## Things to do`, ``);
    lines.push(...digest.action_items.map((item) => `- [ ] ${item}`));
  }

  /*
   * Deliberately quiet, and deliberately present. An empty notes array is a
   * claim that the model understood everything; a non-empty one is the reason
   * to go and check the transcript.
   */
  if (digest.notes.length) {
    lines.push(
      ``,
      `## Some parts were unclear`,
      ``,
      `The transcript was too garbled to summarise these confidently, so they were ` +
        `flagged rather than guessed at:`,
      ``,
      ...digest.notes.map((unclear) => `- ${unclear}`),
    );
  }

  if (includeTranscript && note.transcript?.full_text) {
    lines.push(``, `## Full transcript`, ``, note.transcript.full_text);
  } else if (note.transcript) {
    lines.push(``, `---`, ``, `_Transcript withheld. Pass include_transcript: true to read it._`);
  }

  const text = lines.join("\n");

  if (text.length > CHARACTER_LIMIT) {
    return (
      `${text.slice(0, CHARACTER_LIMIT)}\n\n` +
      `_[Truncated at ${CHARACTER_LIMIT} characters.${
        includeTranscript ? " Omit include_transcript to see the digest alone." : ""
      }]_`
    );
  }

  return text;
}
