import { stat } from "node:fs/promises";
import { extname, isAbsolute } from "node:path";

import { ACCEPTED_EXTENSIONS, MAX_UPLOAD_BYTES } from "./constants.js";

/**
 * Local pre-flight before an upload.
 *
 * The API is the authority on what it will accept - these checks exist so the
 * obvious mistakes (wrong path, a PDF, a file far over the limit) come back
 * instantly and in plain words, instead of after pushing bytes across the wire
 * only to be refused. Anything this cannot know locally, such as duration, is
 * left to the API, whose refusal is passed through verbatim.
 *
 * @returns a sentence explaining the problem, or null when the file looks fine
 */
export async function preflight(filePath: string): Promise<string | null> {
  if (!isAbsolute(filePath)) {
    return `file_path must be absolute. Got "${filePath}".`;
  }

  let info;
  try {
    info = await stat(filePath);
  } catch {
    return `There is no file at ${filePath}. Check the path, including its spelling and any spaces.`;
  }

  if (info.isDirectory()) {
    return `${filePath} is a folder, not an audio file.`;
  }

  if (!info.isFile()) {
    return `${filePath} is not a regular file.`;
  }

  if (info.size === 0) {
    return `${filePath} is empty (0 bytes). It may not have finished downloading or copying.`;
  }

  const extension = extname(filePath).replace(".", "").toLowerCase();

  if (!extension) {
    return (
      `${filePath} has no file extension, so the format cannot be determined. ` +
      `Accepted: ${ACCEPTED_EXTENSIONS.join(", ")}.`
    );
  }

  if (!ACCEPTED_EXTENSIONS.includes(extension as (typeof ACCEPTED_EXTENSIONS)[number])) {
    return (
      `.${extension} is not an audio format this app reads. ` +
      `Accepted: ${ACCEPTED_EXTENSIONS.join(", ")}.`
    );
  }

  if (info.size > MAX_UPLOAD_BYTES) {
    const mb = (info.size / 1024 / 1024).toFixed(1);
    const limit = (MAX_UPLOAD_BYTES / 1024 / 1024).toFixed(0);
    return `That file is ${mb}MB, over the ${limit}MB limit.`;
  }

  return null;
}
