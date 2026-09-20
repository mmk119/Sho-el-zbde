/**
 * The Sho el Zbde instance this server talks to.
 *
 * There is no API key here and there never should be. The Laravel app holds the
 * OpenAI credentials; this server is a client of the local HTTP API and nothing
 * more.
 */
export const BASE_URL = (process.env.SHO_EL_ZBDE_URL ?? "http://localhost:8000").replace(/\/+$/, "");

/**
 * Extensions the app's own accepted_mimes list covers. Checked locally so an
 * obvious mistake (a PDF, a typo'd path) is answered instantly instead of after
 * a 100MB upload. The API remains the authority - see validation.ts.
 */
export const ACCEPTED_EXTENSIONS = [
  "mp3",
  "m4a",
  "wav",
  "ogg",
  "opus",
  "aac",
  "flac",
  "amr",
  "webm",
  "mp4",
  "3gp",
] as const;

/** Mirrors MAX_UPLOAD_MB. Only used to fail fast; the API enforces the real cap. */
export const MAX_UPLOAD_BYTES = Number(process.env.SHO_EL_ZBDE_MAX_UPLOAD_MB ?? 100) * 1024 * 1024;

/** Uploads are slow; reading a digest is not. */
export const UPLOAD_TIMEOUT_MS = 120_000;
export const READ_TIMEOUT_MS = 20_000;

/** Guard against a pathological transcript filling the caller's context. */
export const CHARACTER_LIMIT = 25_000;
