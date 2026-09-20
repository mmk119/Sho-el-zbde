import { openAsBlob } from "node:fs";
import { basename } from "node:path";

import { BASE_URL, READ_TIMEOUT_MS, UPLOAD_TIMEOUT_MS } from "./constants.js";
import { ApiFailed, ApiRefused, ApiUnreachable, NoteExpired, NoteNotFound } from "./errors.js";
import type { NoteEnvelope, NotePayload, SubmitResponse } from "./types.js";

/**
 * Pulls the human-readable sentences out of a Laravel 422.
 *
 * The app already phrases these for a reader - "This note is 21 minutes long.
 * The limit is 20 minutes." - so they are passed through rather than
 * reworded. Re-describing them here would mean two places to keep in step.
 */
function refusalReasons(body: unknown): string[] {
  if (typeof body !== "object" || body === null) {
    return ["The upload was refused, but no reason was given."];
  }

  const { errors, message } = body as { errors?: Record<string, string[]>; message?: string };

  if (errors) {
    const all = Object.values(errors).flat().filter(Boolean);
    if (all.length) return all;
  }

  return [message ?? "The upload was refused, but no reason was given."];
}

async function readJson(response: Response): Promise<unknown> {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

/** Every call goes through here so connection failures read the same way. */
async function request(path: string, init: RequestInit, timeoutMs: number): Promise<Response> {
  try {
    return await fetch(`${BASE_URL}${path}`, {
      ...init,
      headers: { Accept: "application/json", ...(init.headers ?? {}) },
      signal: AbortSignal.timeout(timeoutMs),
    });
  } catch (error) {
    // A refused connection and a DNS failure both mean "it is not there".
    if (error instanceof Error && error.name === "TimeoutError") throw error;
    throw new ApiUnreachable(error);
  }
}

/**
 * POST /api/voice-notes
 *
 * Uses openAsBlob so the file is streamed rather than read into memory - a
 * 100MB note should not become a 100MB buffer.
 */
export async function submitVoiceNote(
  filePath: string,
  languageHint?: string,
  promptHint?: string,
): Promise<SubmitResponse> {
  const form = new FormData();
  form.set("file", await openAsBlob(filePath), basename(filePath));

  if (languageHint) form.set("language_hint", languageHint);
  if (promptHint) form.set("prompt_hint", promptHint);

  const response = await request("/api/voice-notes", { method: "POST", body: form }, UPLOAD_TIMEOUT_MS);

  if (response.status === 422) {
    throw new ApiRefused(refusalReasons(await readJson(response)));
  }

  if (!response.ok) {
    throw new ApiFailed(response.status);
  }

  return (await response.json()) as SubmitResponse;
}

/** GET /api/voice-notes/{token} */
export async function fetchNote(token: string): Promise<NotePayload> {
  const response = await request(
    `/api/voice-notes/${encodeURIComponent(token)}`,
    { method: "GET" },
    READ_TIMEOUT_MS,
  );

  if (response.status === 404) throw new NoteNotFound(token);

  if (response.status === 410) {
    const body = (await readJson(response)) as { message?: string } | null;
    throw new NoteExpired(
      body?.message ?? "This voice note has passed its retention window and has been deleted.",
    );
  }

  if (!response.ok) throw new ApiFailed(response.status);

  const envelope = (await response.json()) as NoteEnvelope;
  return envelope.data;
}
