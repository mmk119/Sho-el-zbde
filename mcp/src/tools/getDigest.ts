import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";

import { fetchNote } from "../api.js";
import { describeError } from "../errors.js";
import { describeDigest, describeFailed, describeInProgress } from "../format.js";

const inputSchema = {
  token: z
    .string()
    .min(1)
    .describe("The share token returned by submit_voice_note"),
  include_transcript: z
    .boolean()
    .default(false)
    .describe(
      "Include the full transcript as well as the digest. Off by default: the transcript is " +
        "the private content of somebody's voice note, and it is long. Turn it on to check " +
        "the digest against what was actually said.",
    ),
};

const outputSchema = {
  token: z.string(),
  status: z.string().describe("pending | transcribing | analyzing | done | failed | expired"),
  ready: z.boolean().describe("True only when a digest is available"),
  urgency: z.string().optional(),
  summary: z.array(z.string()).optional(),
  questions: z.array(z.string()).optional(),
  action_items: z.array(z.string()).optional(),
  unclear: z.array(z.string()).optional().describe("Passages the model could not make out"),
};

export function registerGetDigest(server: McpServer): void {
  server.registerTool(
    "get_digest",
    {
      title: "Read a voice note digest",
      description: `Read the digest of a previously submitted voice note.

Args:
  - token (string, required): the token from submit_voice_note
  - include_transcript (boolean, optional, default false): also return the full transcript

Behaviour by status:
  - done: returns the summary, the questions the speaker asked the listener, key
    details (dates, times, amounts, names, places), things to do, the urgency, and
    a note of any passages that were unclear.
  - pending / transcribing / analyzing: says it is still working and to ask again
    in a few seconds. This is NOT an error - the pipeline is doing its job.
  - failed: returns the readable reason. If a transcript survived, says so.
  - expired: says the link has passed its retention window.

Returns:
  {
    "token": string,
    "status": "pending" | "transcribing" | "analyzing" | "done" | "failed" | "expired",
    "ready": boolean,              // true only when a digest exists
    "urgency": "low" | "normal" | "high",   // when ready
    "summary": string[],                    // when ready
    "questions": string[],                  // when ready
    "action_items": string[],               // when ready
    "unclear": string[]                     // when ready; empty means nothing was garbled
  }

The transcript is withheld unless include_transcript is true. Do not ask for it
by default - the digest is the thing that was wanted, and the transcript is
private and long.

Examples:
  - "Is my voice note ready?" -> token only
  - "Check the summary against what she actually said" -> include_transcript=true`,
      inputSchema,
      outputSchema,
      annotations: {
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: true,
      },
    },
    async ({ token, include_transcript }) => {
      try {
        const note = await fetchNote(token);

        if (note.status === "failed") {
          return {
            content: [{ type: "text", text: describeFailed(note) }],
            structuredContent: { token, status: note.status, ready: false },
          };
        }

        // Still working is a normal answer, not a failure.
        if (note.status !== "done") {
          return {
            content: [{ type: "text", text: describeInProgress(note) }],
            structuredContent: { token, status: note.status, ready: false },
          };
        }

        const digest = note.digest;

        return {
          content: [{ type: "text", text: describeDigest(note, include_transcript) }],
          structuredContent: {
            token,
            status: note.status,
            ready: digest !== null && digest !== undefined,
            ...(digest
              ? {
                  urgency: digest.urgency,
                  summary: digest.summary,
                  questions: digest.questions,
                  action_items: digest.action_items,
                  unclear: digest.notes,
                }
              : {}),
          },
        };
      } catch (error) {
        return {
          content: [{ type: "text", text: describeError(error) }],
          structuredContent: {
            token,
            status: error instanceof Error && error.name === "NoteExpired" ? "expired" : "unknown",
            ready: false,
          },
          isError: true,
        };
      }
    },
  );
}
