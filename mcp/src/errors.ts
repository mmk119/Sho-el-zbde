import { BASE_URL } from "./constants.js";

/**
 * Failures the caller can do something about, kept apart from each other
 * because each deserves a different sentence.
 */
export class ApiUnreachable extends Error {
  constructor(cause?: unknown) {
    super(
      `Could not reach Sho el Zbde at ${BASE_URL}.\n\n` +
        `Start it with:\n` +
        `  php artisan serve\n` +
        `  php artisan queue:work --sleep=1\n\n` +
        `Both are needed: without the queue worker an upload is accepted but never processed. ` +
        `If it runs on a different port, set SHO_EL_ZBDE_URL.`,
    );
    this.name = "ApiUnreachable";
    this.cause = cause;
  }
}

/** A 422. The app's own messages are already written for a human, so they pass through verbatim. */
export class ApiRefused extends Error {
  constructor(public readonly reasons: string[]) {
    super(reasons.join("\n"));
    this.name = "ApiRefused";
  }
}

export class NoteExpired extends Error {
  constructor(message: string) {
    super(message);
    this.name = "NoteExpired";
  }
}

export class NoteNotFound extends Error {
  constructor(token: string) {
    super(
      `No voice note matches the token ${token}.\n\n` +
        `Share tokens are 40 hex characters and exact. Check it was not truncated, ` +
        `or the note may already have passed its retention window and been deleted.`,
    );
    this.name = "NoteNotFound";
  }
}

export class ApiFailed extends Error {
  constructor(status: number) {
    super(`Sho el Zbde returned HTTP ${status}. The app is running but something went wrong inside it.`);
    this.name = "ApiFailed";
  }
}

/** Turns anything thrown into the sentence a caller should read. */
export function describeError(error: unknown): string {
  if (
    error instanceof ApiUnreachable ||
    error instanceof ApiRefused ||
    error instanceof NoteExpired ||
    error instanceof NoteNotFound ||
    error instanceof ApiFailed
  ) {
    return error.message;
  }

  if (error instanceof Error && error.name === "TimeoutError") {
    return "The request to Sho el Zbde timed out. A large upload can take a while; try again.";
  }

  return `Unexpected error: ${error instanceof Error ? error.message : String(error)}`;
}
