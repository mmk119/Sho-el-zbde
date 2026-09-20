import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";

import { submitVoiceNote } from "../api.js";
import { ACCEPTED_EXTENSIONS } from "../constants.js";
import { describeError } from "../errors.js";
import { preflight } from "../validation.js";

const inputSchema = {
  file_path: z
    .string()
    .min(1)
    .describe("Absolute path to a local audio file, e.g. C:\\Users\\me\\Downloads\\note.m4a or /home/me/note.m4a"),
  language_hint: z
    .string()
    .max(16)
    .regex(/^[a-zA-Z-]+$/, "Use an ISO-639-1 code such as 'ar' or 'en'")
    .optional()
    .describe(
      "ISO-639-1 code of the language being spoken, e.g. 'ar' or 'en'. Omit for auto detect. " +
        "Passing 'ar' is what enables the Lebanese dialect vocabulary.",
    ),
  names: z
    .string()
    .max(500)
    .optional()
    .describe(
      "Free text of names and places expected in the recording, e.g. 'Teta Mariam, Jounieh'. " +
        "Improves proper-noun accuracy more than any other input.",
    ),
};

const outputSchema = {
  token: z.string().describe("Share token for this note; pass it to get_digest"),
  status: z.string().describe("Status at the moment of submission, always 'pending'"),
};

export function registerSubmitVoiceNote(server: McpServer): void {
  server.registerTool(
    "submit_voice_note",
    {
      title: "Submit a voice note",
      description: `Upload a local audio file to Sho el Zbde for transcription and summarising.

Returns immediately with a token. The work happens in a background queue, so the
digest is NOT ready when this returns - call get_digest with the token after a
few seconds.

Args:
  - file_path (string, required): absolute path to the audio file
  - language_hint (string, optional): ISO-639-1 code, e.g. 'ar', 'en'. Omit for auto detect.
  - names (string, optional): names and places expected in the recording

Accepted formats: ${ACCEPTED_EXTENSIONS.join(", ")}. Up to 100MB and 20 minutes.

Returns:
  { "token": string, "status": "pending" }

Examples:
  - "Summarise the voice note at /home/me/teta.m4a" -> file_path=/home/me/teta.m4a
  - "It's in Lebanese Arabic and mentions Teta Mariam" -> language_hint='ar', names='Teta Mariam'

Error handling:
  - Says so plainly if the file is missing, empty, a folder, or not an audio format
  - Passes through the app's own refusal if it is too long or too large
  - Says how to start the app if it is not running`,
      inputSchema,
      annotations: {
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: false,
        openWorldHint: true,
      },
    },
    async ({ file_path, language_hint, names }) => {
      // Fail fast on what can be known locally, before pushing any bytes.
      const problem = await preflight(file_path);

      if (problem) {
        return { content: [{ type: "text", text: problem }], isError: true };
      }

      try {
        const result = await submitVoiceNote(file_path, language_hint, names);

        return {
          content: [
            {
              type: "text",
              text:
                `Uploaded. Token: ${result.token}\n\n` +
                `It is now being processed. Call get_digest with this token in a few seconds; ` +
                `until then it will report that it is still working, which is not an error.`,
            },
          ],
          structuredContent: { token: result.token, status: result.status },
        };
      } catch (error) {
        return { content: [{ type: "text", text: describeError(error) }], isError: true };
      }
    },
  );
}
