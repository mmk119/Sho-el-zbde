# Sho el Zbde — working brief

*This file is gitignored. It is my brief, not documentation for visitors. Keep
"Current state" accurate; update it at the end of every phase.*

---

## What it is

A web app that turns long voice notes into the part people actually needed.
Someone sends a 9 minute voice note, you upload it here, and you get back:

- a short summary
- the questions the sender directed at you
- key details — dates, times, amounts, names, places
- an urgency level
- the full transcript

"Sho el zbde?" is Lebanese Arabic for "what's the gist?".

## Stack

| Piece | Choice |
| --- | --- |
| Framework | Laravel 12, PHP 8.3 |
| Database | SQLite (single file) |
| Queues | Laravel database queue driver |
| Queue monitoring | Laravel Horizon |
| Live progress | Polling the GET endpoint every 3s |
| Frontend | Blade + Livewire |
| Audio | ffmpeg |
| Transcription | Whisper |
| Analysis | an LLM API |
| Local dev | PHP + ffmpeg, nothing else |

Local setup is deliberately two dependencies: **PHP and ffmpeg**. No database
server, no Redis, no Docker. See the decisions log for the upgrade paths, which
all stay open as config changes.

## Architecture rules

1. **Every external AI service sits behind an interface** in `app/Contracts`, with
   implementations in `app/Services`. Never call an SDK directly from a controller
   or from a job body. Swapping OpenAI Whisper for a self-hosted whisper.cpp must
   be a one-line binding change.
2. **All slow work runs in queued jobs.** The upload endpoint returns immediately.
3. **Never trust LLM output.** Validate the JSON against a schema before storing.
   If parsing fails: retry once with a stricter instruction, then mark the note
   failed with a readable error message.
4. **No secrets in code.** Everything through `config()` reading from `.env`.
5. **Write a test for each job as it is built.**

## Data model

```
voice_notes   id, user_id (nullable), public_token, original_filename,
              storage_path, duration_seconds, normalized_duration_seconds,
              language_hint, language_detected, status, error_message,
              expires_at, timestamps
              status enum: pending | transcribing | analyzing | done | failed

              duration_seconds            the ORIGINAL upload's duration. This
                                          is what the 20 minute limit is checked
                                          against and what the user is shown.
              normalized_duration_seconds what NormalizeAudio actually produced
                                          after silence trimming, i.e. what got
                                          sent to Whisper. Null until that job
                                          runs. This is the billable number and
                                          it is what feeds usage_logs.

transcripts   id, voice_note_id, full_text (longtext), segments (json), word_count

digests       id, voice_note_id, summary (json), questions (json), entities (json),
              action_items (json), notes (json), urgency enum (low|normal|high),
              model_used, tokens_used

              notes  string[] - anything the model could not make out, or where
                     the transcript was too garbled to summarize confidently.
                     Empty array when clean, never null. The result page shows
                     it as a small "some parts were unclear" line, not a
                     prominent error.

usage_logs    id, user_id (nullable), ip_hash, voice_note_id, duration_seconds,
              cost_estimate
              duration_seconds here is the NORMALIZED duration - we are billed
              on what we sent, not on what was uploaded.
```

`public_token` is a random unguessable string used in the share URL.

## API

```
POST /api/voice-notes
     multipart: file, language_hint
     -> { token, status }

GET  /api/voice-notes/{token}
     -> status, error_message, original_filename, duration_seconds,
        language_detected, audio_url, digest object, transcript object
```

## Job chain

```
NormalizeAudio -> TranscribeAudio -> AnalyzeTranscript -> NotifyReady
```

Each job updates `voice_notes.status` and writes a human readable error on
failure. `NormalizeAudio` uses ffmpeg to convert to mono 16kHz wav and trim long
silences.

## Limits and safety

- Max upload 100MB, max duration 20 minutes — **enforced on the original
  upload, before NormalizeAudio is dispatched.** Silence trimming shortens the
  audio, so checking after normalization would let over-length notes through.
- Rate limit anonymous uploads by IP.
- Audio deleted after 30 days by a scheduled cleanup job.
- **Do not log transcripts.** These are private messages.
- Result URLs must not be guessable.

## Language

Language agnostic. The hint is a dropdown with auto detect as the default.
Arabic dialect is the hardest test case, **not a special code path**. The result
page flips to RTL when the detected language is Arabic (or any RTL script).

---

## Phases

| # | Scope | Status |
| --- | --- | --- |
| 0 | `transcribe-test.php` — standalone Whisper quality probe | **complete** |
| 1 | Laravel install, migrations, models, both endpoints, job chain stubbed | **complete** |
| 2 | Real NormalizeAudio + TranscribeAudio behind `TranscriptionService` | not started |
| 3 | AnalyzeTranscript with strict JSON validation behind `AnalysisService` | not started |
| 4 | Livewire frontend: upload, progress, result | not started |
| 5 | Polling the GET endpoint for progress | not started |
| 6 | Rate limits, expiry, cleanup job, Horizon | not started |
| 7 | Deployment | not started |
| — | *Optional later:* Reverb WebSockets, with polling kept as the fallback | deferred |
| — | *Optional later:* Docker Compose | deferred |

Work one phase at a time. Stop at the end of each, update this file, report, and
wait. Ask before adding any package not already in `composer.json`.

---

## Current state

**As of 2026-09-19 — end of Phase 0.**

### Built

**Phase 0** — `transcribe-test.php`, the standalone Whisper probe. No framework,
no composer. Reads `.env` itself, normalizes with the exact ffmpeg filter chain
`NormalizeAudio` uses, reports language/timing/cost, flags RTL. Complete.

**Phase 1** — the Laravel app.

- `composer.json` / `composer.lock` — Laravel 12 skeleton, no packages added
  beyond what ships with it. Sanctum was deliberately NOT installed:
  `routes/api.php` is registered by hand in `bootstrap/app.php` rather than via
  `artisan install:api`, which would have pulled in a package nobody asked for.
- `config/shoelzbde.php` — every limit, path and audio setting, all reading
  `env()`. Nothing hardcoded in app code.
- **Migrations** for `voice_notes`, `transcripts`, `digests`, `usage_logs`,
  carrying both `duration_seconds` and `normalized_duration_seconds`, with the
  reason for the split written into the migration itself.
- **Models** `VoiceNote`, `Transcript`, `Digest`, `UsageLog`. `VoiceNote` mints
  its own 40-hex-character token from `random_bytes` on create, defaults
  `expires_at` to +30 days, and funnels every status change through
  `markStatus()` / `markFailed()` so no job invents its own transition.
- **Enums** `VoiceNoteStatus` and `Urgency`, replacing loose strings.
  `isTerminal()` is what stops a late job reopening a finished or failed note.
- **`AudioInspector` contract + `FfprobeAudioInspector`**, bound in
  `AudioServiceProvider`. Needed because the duration limit has to be checked
  before dispatch, and that means probing the original upload. Behind an
  interface like every other external tool.
- **Both endpoints**, plus `GET /api/voice-notes/{token}/audio` so `audio_url`
  in the payload resolves to something real.
- **The four jobs chained** via `Bus::chain`, each sleeping 2s and advancing
  status. Shared behaviour lives in the `TracksVoiceNoteProgress` trait:
  skip terminal notes, and write a readable sentence on failure rather than
  exception text.
- **29 tests, 74 assertions, all passing.** Four job classes share
  `JobChainTestCase`, which asserts the three things every job must do rather
  than copying them four times.

### Not built

Phases 2–7. Specifically: no AI API is called anywhere in the codebase yet, the
jobs are sleeps, `usage_logs` has no writer, there is no frontend, no rate
limiting, no cleanup job and no Horizon.

### Toolchain (installed 2026-09-19)

Chocolatey turned out to be broken on this machine (silent exit 1, no output even
when redirected to a file) and the shell was not elevated, so everything was
installed **portable, per-user, no admin**:

| Tool | Version | Location |
| --- | --- | --- |
| PHP | 8.3.33 NTS x64 | `C:	ools\php83` |
| ffmpeg / ffprobe | 9.0.1 essentials | `C:	oolsfmpegin` |
| Composer | 2.10.3 | `C:	ools\php83\composer.phar` + `composer.bat` |

Both directories were appended to the **user** PATH. `php.ini` was created from
`php.ini-development` with `extension_dir="ext"` and these enabled and verified
loaded: `curl`, `mbstring`, `openssl`, `pdo_sqlite`, `sqlite3`, `fileinfo`,
`zip`. Nothing was missing from the distribution; they were just commented out.

`curl.cainfo` and `openssl.cafile` both point at `C:	ools\php83\cacert.pem`
(from curl.se). Without this, every HTTPS call from PHP fails with "unable to get
local issuer certificate" — the Windows PHP builds ship no CA bundle. **Laravel's
HTTP client will hit the same wall**, so this is not Phase 0 specific.

Composer was installed via the official installer with its SHA-384 verified
against `composer.github.io/installer.sig`.

### Blockers

None.

**Phase 0 answered its question on 2026-09-19.** Tested against a 3 minute
excerpt of a Lebanese civil war interview (natural dialect, broadcast audio).
The user read both transcripts and judged the prompted run **usable**: dialect
forms came through correctly, and every number and place survived. Remaining
errors were contextually recoverable. Whisper is good enough to build on.

Caveat to revisit: the test material was clean single-speaker broadcast audio.
The real use case is a phone voice note with background noise. Not yet tested.

### Decisions and open questions

- **Cost math is hardcoded to the whisper-1 list price** ($0.006/min) in the
  Phase 0 script. It is a throwaway sanity number, not a billing path. The real
  `usage_logs.cost_estimate` in Phase 6 must not copy this constant.
- **`response_format`:** `verbose_json` for `whisper-*` models (gives segments
  and detected language, which map onto `transcripts.segments` and
  `voice_notes.language_detected`); the `gpt-4o-transcribe` family only returns
  plain `json`, so segments are unavailable there. If Phase 0 shows gpt-4o
  transcribing Lebanese Arabic noticeably better, we need a decision on whether
  segments are worth keeping — they are currently only used for display.
- **Analysis provider is unchosen.** `.env.example` lists Anthropic vars as a
  placeholder because the brief only says "an LLM API". Settle this in Phase 3;
  either way it sits behind `AnalysisService` so it is a binding change.
- **The API key was pasted into chat in plaintext**, prefixed `VITE_`. It is
  compromised and must be rotated. `VITE_`-prefixed vars are bundled into
  client-side JS by Vite and shipped to every browser — an OpenAI key must never
  carry that prefix. Stored server-side as `OPENAI_API_KEY`. Flagged to the user
  on 2026-09-19; rotation not yet confirmed.
- **Silence trimming is lossy by design.** `silenceremove` with a 1.5s /
  -40dB threshold changes the audio duration, so the duration Whisper bills for
  is not the duration the user uploaded. The 20-minute limit must therefore be
  enforced on the **original** file, before normalization — and
  `voice_notes.duration_seconds` should record the original duration.

- **Stack simplified on 2026-09-19** to cut install friction. MySQL → SQLite,
  Redis → Laravel's database queue driver, Reverb → polling the GET endpoint
  every 3s, Docker dropped from the plan. **Reason:** local setup is now two
  dependencies, PHP and ffmpeg, and nothing else. The job chain and the
  `TranscriptionService` / `AnalysisService` interfaces are unchanged — that was
  the condition. Every upgrade path stays open as a config change rather than a
  rewrite: `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=redis`,
  `BROADCAST_CONNECTION=reverb` with polling demoted to fallback. Nothing in app
  code may assume SQLite, the database queue driver, or polling. Reverb and
  Docker move to optional later phases; Phase 7 becomes plain deployment.
- **`normalized_duration_seconds` added to `voice_notes`** on 2026-09-19. The
  20 minute and 100MB limits are checked against the original upload before
  `NormalizeAudio` is dispatched; `duration_seconds` keeps the original and is
  what the user sees; `normalized_duration_seconds` records what was actually
  sent to Whisper after silence trimming, and that is the number that flows into
  `usage_logs.duration_seconds` and `cost_estimate`, because it is what we are
  billed on.
- **No variable in this project carries a `VITE_` prefix**, confirmed by grep on
  2026-09-19. This app has no Vite frontend; Vite inlines such vars into the
  browser bundle. The only `VITE` strings in the repo are in this decisions log.
  The leaked key is being rotated by the user.
- **Verified end to end on 2026-09-19** with a synthetic English WAV generated by
  Windows SAPI: ffprobe read the duration, ffmpeg normalized to 16kHz mono,
  Whisper returned `english` with 4 segments in 10.6s (1.3x realtime, ~$0.0014).
  This proves the plumbing — .env parsing, the ffmpeg filter chain, auth, the
  multipart upload, verbose_json parsing, cost math. It proves **nothing** about
  dialect quality, which is the actual reason Phase 0 exists.
- **Key rotation closed out 2026-09-19.** The leaked key now returns HTTP 401
  (revoked at the dashboard); the replacement in `.env` authenticates. Verified
  by calling `/v1/models` with each.

---

## Phase 0 findings — these bind Phases 2 and 3

- **The prompt is load-bearing, not optional.** Same audio, same model: the bare
  run hallucinated a phantom opening phrase and leaked non-Arabic characters
  (a Greek letter, an English word) into Arabic text. The prompted run did
  neither. **`TranscribeAudio` must ALWAYS send a prompt** — there is no
  "no prompt" code path. Build a default prompt holding common Lebanese words
  plus the app name, and let the user optionally append their own names and
  places from the upload form. Treat the default prompt as config, not a
  hardcoded string.
- **Segments are not reliable.** The same three minutes of audio produced 66
  segments bare and 9 with a prompt — a structural change caused by supplying
  vocabulary alone. Store `transcripts.segments` because it is cheap, but
  **nothing in the app may depend on segment boundaries**: no indexing, no
  seeking, no chunked analysis, no "jump to timestamp" feature built on them.
  Display only, and even then defensively.
- **The analysis prompt must state that the transcript is machine generated and
  may contain errors**, especially on proper nouns and code-switched words, and
  must instruct the model to **flag unclear passages rather than invent meaning**.
  A digest that confidently reports a wrong name or a wrong amount is worse than
  one that says it could not tell. This shapes the `AnalysisService` prompt in
  Phase 3 and should be reflected in the digest schema — unclear passages need
  somewhere to go.
- **Silence trimming saved nothing on continuous speech** (3:00 in, 3:00 out).
  `normalized_duration_seconds` will often equal `duration_seconds`. Do not
  assume normalization shortens anything.

---

## Phase 1 decisions

- **Sanctum was not installed.** `artisan install:api` is the documented way to
  get `routes/api.php`, but it adds Laravel Sanctum. Nothing in the brief needs
  API tokens, so the routes file is registered directly in `bootstrap/app.php`
  instead. Zero packages beyond the Laravel skeleton.
- **`NormalizeAudio` does not write `normalized_duration_seconds` in Phase 1.**
  Copying `duration_seconds` into it would have made the pipeline look complete
  while planting a fabricated billing number in the column `usage_logs` charges
  against. It stays null until Phase 2 measures it for real. There is a test
  asserting this.
- **Status flow is `pending -> transcribing -> analyzing -> done`.** The enum has
  no "normalizing" state, so `NormalizeAudio` hands off by setting
  `transcribing`. `AnalyzeTranscript` deliberately leaves the note in
  `analyzing`; `NotifyReady` is the only job that writes `done`.
- **Jobs are re-entrant and refuse terminal notes.** Every `handle()` re-reads
  the note and returns early if it is already `done` or `failed`. Without this a
  retried or delayed job could reopen a finished note.
- **Failure messages never contain exception text.** `failed()` writes a fixed
  readable sentence. Exception messages can carry file paths now and, once the
  AI calls land in Phase 2, API payloads derived from private audio. There is a
  test asserting no leakage.
- **The duration limit is enforced in `StoreVoiceNoteRequest::after()`**, which
  probes the uploaded temp file before anything is stored or dispatched. Tests
  cover over-length, over-size and unreadable audio, and each asserts
  `Bus::assertNothingDispatched()` plus zero rows written.
- **Laravel ships Vite** (`vite.config.js`, `package.json`) for asset bundling in
  Phase 4. This does not conflict with the no-`VITE_`-prefix rule, which is about
  environment *variables* being inlined into the browser bundle. Confirmed: no
  variable in this project carries the prefix.

---

## Pre-Phase-2 decisions

- **`CLAUDE.md` is tracked in git as of 2026-09-19.** It carries no key material,
  only prose describing the rotation incident, and the decisions log is worth
  having in history. `.env` stays ignored.
- **`digests.notes` added** (`string[]`, json column, nullable at the database
  level but always written as an array). This is the landing place for the
  Phase 0 finding that the analysis prompt must flag unclear passages rather
  than invent meaning - an instruction to flag is useless without somewhere for
  the flag to go. Phase 3 must populate it, and the schema validation must
  require the key to be present even when empty. Presentation is deliberately
  quiet: a small "some parts were unclear" line, never an error state. A digest
  with notes is still a good digest.
