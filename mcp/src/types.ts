/** Shapes returned by the Sho el Zbde HTTP API. */

export type NoteStatus = "pending" | "transcribing" | "analyzing" | "done" | "failed";

export interface SubmitResponse {
  token: string;
  status: NoteStatus;
}

export interface DigestPayload {
  summary: string[];
  questions: string[];
  entities: {
    dates?: string[];
    times?: string[];
    amounts?: string[];
    names?: string[];
    places?: string[];
  };
  action_items: string[];
  /** Passages the model could not make out. Empty array when the transcript was clean. */
  notes: string[];
  urgency: "low" | "normal" | "high";
  model_used?: string | null;
}

export interface TranscriptPayload {
  full_text: string;
  word_count: number;
  /**
   * Stored but deliberately unused. Segment boundaries shift when the
   * transcription prompt changes, so nothing may depend on them.
   */
  segments: unknown[] | null;
}

export interface NotePayload {
  token: string;
  status: NoteStatus;
  error_message: string | null;
  original_filename: string;
  duration_seconds: number | null;
  language_detected: string | null;
  audio_url: string;
  expires_at: string | null;
  transcript?: TranscriptPayload | null;
  digest?: DigestPayload | null;
}

/** The show endpoint wraps its payload in a Laravel resource envelope. */
export interface NoteEnvelope {
  data: NotePayload;
}
