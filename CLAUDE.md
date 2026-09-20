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
| Framework | Laravel 13, PHP 8.3 |
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
| 2 | Real NormalizeAudio + TranscribeAudio behind `TranscriptionService` | **complete** |
| 3 | AnalyzeTranscript with strict JSON validation behind `AnalysisService` | **complete** |
| 4 | Livewire frontend: upload, progress, result | **complete** (design pass done) |
| 5 | Polling the GET endpoint for progress | **done as part of Phase 4** |
| 6 | Rate limits, expiry, cleanup job | **complete** (Horizon dropped, see below) |
| 7 | Deployment | **not started** — checklist at the bottom of this file |
| — | *Optional later:* Reverb WebSockets, with polling kept as the fallback | deferred |
| — | *Optional later:* Docker Compose | deferred |

Work one phase at a time. Stop at the end of each, update this file, report, and
wait. Ask before adding any package not already in `composer.json`.

---

## Current state

**As of 2026-09-19 — end of Phase 0.**

### Built

Everything below exists, is tested, and has been exercised in a real browser.

**Phase 0 — `transcribe-test.php`.** Standalone Whisper probe. No framework, no
composer. Reads `.env` itself, normalizes with the exact ffmpeg filter chain the
app uses, reports language/timing/cost, flags RTL. Still useful for trying a new
model or prompt without touching the app.

**Phase 1 — the Laravel app.** Laravel 13 skeleton. `config/shoelzbde.php` holds
every limit, path and audio setting, all reading `env()`. Migrations and models
for `voice_notes`, `transcripts`, `digests`, `usage_logs`. `VoiceNote` mints a
40-hex-character token from `random_bytes`, defaults `expires_at`, and funnels
every status change through `markStatus()` / `markFailed()`. Enums for status and
urgency. Both API endpoints plus `GET /api/voice-notes/{token}/audio`. The four
jobs chained via `Bus::chain`, sharing `TracksVoiceNoteProgress`.

**Phase 2 — real audio and transcription.** `FfmpegAudioNormalizer` behind
`AudioNormalizer`: mono 16kHz pcm_s16le, silence trimmed at 1.5s/-40dB.
`OpenAiTranscriptionService` behind `TranscriptionService`, with a mandatory
prompt enforced in three places. Errors sorted into rate-limited / unavailable /
rejected. `usage_logs` opened at upload, closed at transcription.

**Phase 3 — the digest.** `OpenAiAnalysisService` behind `AnalysisService`,
`gpt-4o`. `DigestSchema` validates before anything is stored; one stricter
re-ask on a schema failure, then give up with the transcript preserved.
`AnalysisPrompt` carries the Phase 0 findings, including the real corruption
examples and the detected language.

**Phase 4 — the frontend.** Livewire 4. Upload page with drag and drop, language
dropdown and the optional names field. Processing view with four steps and a
filling rail, polling every 3s. Result page at `/r/{token}` with the digest and
transcript together. RTL from `language_detected`, dark mode, mobile first.
Styled 404/410/500/503 pages.

**Phase 6 — safe to run.** Per-IP throttle counted from `usage_logs`. Expiry
enforced at read time (410 from the API, a plain page on the web). `zbde:purge`
scheduled daily, deleting both audio files and the note. The normalized wav is
deleted as soon as transcription succeeds.

**192 tests, 408 assertions.** Including five that drive the real ffmpeg binary,
and a suite covering the three failure modes end to end.

**The MCP server** (`mcp/`, Node + TypeScript, stdio). A client of the HTTP API
and nothing else - it holds no credentials. Two tools: `submit_voice_note` and
`get_digest`. Separate npm project; nothing in the Laravel app knows it exists.
Its README carries the Claude Desktop config JSON.

### Not built

- **Phase 7, deployment.** No Dockerfile, no deploy script, no CI, no server
  config. Checklist at the bottom of this file.
- **`NotifyReady` sends nothing.** It flips the note to `done` and that is all,
  which is correct while polling is the delivery mechanism. If email or push is
  ever wanted, that is where it goes.
- **No accounts.** `user_id` is nullable everywhere and nothing populates it.
  Every note is anonymous, and the share token is the entire access model.
- **No Reverb, no Docker, no Horizon.** All three were deliberately dropped —
  see the decisions log. Polling replaced Reverb, Docker was dropped from the
  plan, and Horizon needs Redis which this project does not use.
- **`transcripts.segments` is stored but unused.** Nothing reads it, by design.

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
- **Analysis provider settled in Phase 3: OpenAI**, `gpt-4o`. Chosen because it
  is the key that exists in `.env` — `ANTHROPIC_API_KEY` was never filled in, so
  an Anthropic driver could not have been run or tested. This is an availability
  decision, not a quality judgement. `AnalysisService` and the driver switch are
  provider-agnostic; adding Anthropic is a new class plus a `match` arm.
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
  "no prompt" code path.

  **Qualified later (see "The prompt language bug" below): this finding is true
  for Arabic, and was measured only on Arabic.** The prompt is still mandatory,
  but it is now chosen by language rather than being one global Arabic string. Build a default prompt holding common Lebanese words
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

---

## Phase 2 decisions

- **Two new columns on `voice_notes`.** `normalized_storage_path` because the
  original and the normalized wav have to coexist — the original is what the
  user plays back and what `duration_seconds` was measured against, so
  normalization cannot overwrite it. `prompt_hint` because the upload form is
  allowed to append names and places to the mandatory prompt, and that string
  has to survive until the job runs.
- **The prompt invariant is enforced in three places**, deliberately redundantly:
  `TranscriptionPrompt::build()` never returns empty (falling back to the app
  name if config is somehow blank), `OpenAiTranscriptionService` throws if
  handed a blank prompt, and the interface has no parameter that can express
  "no prompt". Phase 0 showed the unprompted path is worse, so it should be
  hard to reach by accident.
- **Errors are sorted into three kinds**, because they want different handling:
  `TranscriptionRateLimited` (wait and retry — not a failure),
  `TranscriptionUnavailable` (transient: 408, 5xx, connection — retry), and
  `TranscriptionRejected` (permanent: 4xx — fail immediately). Retrying an
  expired API key for thirty minutes helps nobody.
- **Retry policy on `TranscribeAudio`:** `retryUntil()` of 30 minutes rather
  than a fixed `$tries`, `maxExceptions = 3`, backoff `[10, 30, 60]`. A 429
  calls `release()` with the `Retry-After` value clamped to 1–300s, which does
  **not** spend the exception budget — so being told to wait many times is fine,
  while three real errors still fail fast. Without the clamp a hostile or buggy
  `Retry-After` could park a job for hours.
- **Response bodies are never put into exception messages.** The body can echo
  the prompt, which carries user-supplied names. There is a test asserting a
  400's body does not reach the exception.
- **`usage_logs` is opened at upload and closed at transcription.** `ip_hash`
  only exists in request context; the billable duration only exists after
  normalization. One row per note, written by `updateOrCreate`, which is also
  what Phase 6 will count for per-IP rate limiting.
- **`cost_per_minute_usd` is config, not a constant.** This resolves the Phase 0
  open question: the throwaway `0.006` from `transcribe-test.php` was not copied
  into app code, it became `TRANSCRIPTION_COST_PER_MINUTE_USD`.
- **The normalizer has real-ffmpeg tests** that build a fixture with a 6 second
  silence in the middle and assert the output is mono/16kHz/pcm_s16le and
  measurably shorter. Everything else in the suite fakes ffmpeg; a typo in the
  filter chain would otherwise only surface in production.
- **Confirmed on real audio 2026-09-19:** the 3 minute interview produced
  `normalized_duration_seconds` 180 against `duration_seconds` 180 — silence
  trimming saved nothing, exactly as the Phase 0 finding predicted for
  continuous speech. The column is doing its job; it simply is not always
  smaller. Do not treat equality as a bug.

---

## Phase 3 decisions

- **Provider is OpenAI `gpt-4o`**, for the reason above: it is the key that
  exists. The interface takes a transcript, a language and a stricter flag —
  nothing OpenAI-shaped — so the swap stays a binding change.
- **Validation lives in `DigestSchema`, not in the driver.** Whichever provider
  is bound, the same rules apply. The driver's only job is to return what the
  model said, decoded, plus a `wasUnparseable` flag.
- **Strict about structure, lenient about taste.** A missing key or wrong type
  is a rejection. Bullet *count* is not: "3 to 5 bullets" is an instruction in
  the prompt, and failing a genuinely short note for producing two bullets would
  turn a good digest into a failed note. The upper rail is 50 items per list, as
  a guard against a runaway response.
- **`notes` is required even when empty**, and there is a dedicated test saying
  so. An omitted `notes` is indistinguishable from "the transcript was perfectly
  clear", which is exactly the confusion the field exists to prevent.
- **Two kinds of retry, kept separate.** Transport errors are the queue's
  problem, on the Phase 2 discipline (30 minute window, 3 exceptions, 429
  releases). Schema failures are handled *inside a single run*: one stricter
  re-ask, then give up. Bouncing the whole job for a shape problem would re-send
  the transcript and pay for it again for no reason.
- **The stricter re-ask talks only about shape.** It says "do not change your
  findings, only fix the shape", so a retry cannot quietly rewrite the digest's
  meaning while fixing a missing key.
- **Failed attempts are still billed.** `askTwiceAtMost()` accumulates cost and
  tokens across both passes, because we are charged for the rejected one too.
  Hiding that would make the analysis line item wrong.
- **`usage_logs` splits the cost by step.** `transcription_cost` is per minute of
  audio, `analysis_cost` is per token, and `cost_estimate` is kept as the running
  total so nothing that already reads it breaks.
- **Nothing in the analysis path logs.** The transcript and the prompt both carry
  private content and a response body can echo either back, so error messages
  carry a status code and nothing else. There is a test asserting a 400's body
  does not reach the exception.

### The language bug, found by running it

The first live run produced a structurally valid digest whose summary was **in
English**, summarising Arabic speech. Schema validation passed, because shape
was never the problem.

The prompt did say "write summary and questions in the SAME LANGUAGE the speaker
used". That was not enough. What fixed it was **naming the detected language**:
the prompt now says "The speaker is speaking arabic. Write summary and questions
in arabic", built from `voice_notes.language_detected` and threaded through the
interface as a real parameter.

Two lessons worth keeping:

1. A general instruction about language loses to a specific one. If a rule
   matters, name the value rather than describing the rule.
2. **Schema validation cannot catch a semantic failure.** Every key was present
   and correctly typed. Structure and correctness are different things, and only
   reading the output caught it. Phase 4 should show the digest next to the
   transcript for exactly this reason.

Confirmed fixed on a re-run: the summary came back in Arabic, no Latin words.

### What the live run showed about `notes`

The model routed `عوامل إقرمية` into `notes` rather than guessing — that is the
same إقليمية corruption the prompt warns about, behaving exactly as intended. A
second entry was a fragment with mixed Latin and Arabic characters, which is the
model quoting something it could not read. Worth an eyeball in Phase 4 to decide
how such fragments should be displayed, since they are meaningless to a reader.

---

## Phase 4 decisions

### Correction: this is Laravel 13, not 12

`composer create-project laravel/laravel` installed **13.32.0** — current stable
at the time — and Phases 1–3 were reported as "Laravel 12". The brief asked for
12. Nothing built so far depends on the difference, but the stack table and the
Phase 1 note have been corrected rather than left wrong.

### Packages

**`livewire/livewire` 4.4 was installed.** It is the one package added beyond
the skeleton, and it was named in the brief from the start. Tailwind 4 and Vite
already ship with Laravel, so styling added nothing.

Two things about Livewire 4 that differ from the v3 documentation most people
have in their heads:

- **The layout lives at `resources/views/layouts/app.blade.php`**, not
  `resources/views/components/layouts/app.blade.php`. v4 defaults to
  `'component_layout' => 'layouts::app'`, a view *namespace* pointing at
  `resources/views/layouts`. The v3 path fails with
  `No hint path defined for [layouts]`, which does not obviously mean "wrong
  directory".
- Alpine still ships with it, so the drag-and-drop, copy buttons, collapsible
  transcript and checklist need no extra dependency.

**Tailwind 4's `@apply` only takes real utilities, not other custom classes.**
`.btn-primary { @apply btn ... }` fails the build with
`Cannot apply unknown utility class`. The shared button base is repeated in each
variant instead.

### Structure

- **`App\Actions\CreateVoiceNote` is now the single place a note enters the
  system.** Both the JSON API and the Livewire form go through it, so the two
  cannot drift on what is stored, logged or dispatched. Validation stays with
  each caller, because each reports failure differently.
- **`App\Support\UploadRules` holds the limits** for the same reason. The web
  form and the API enforce identical size, type and duration rules, all still
  checked against the original upload before dispatch.
- **One Livewire component covers processing and result**, because they are the
  same URL at different moments. It polls only while the note is non-terminal.
- **The four steps are derived from `status`, not stored.** A note processed
  before this page existed still renders correctly, and there is no new column
  to keep in sync.

### Presentation

- **RTL is driven by `language_detected`**, with a script-detection fallback on
  the transcript for notes where detection returned null. It flips the digest
  and transcript only — headings, chips labels and the button row stay LTR,
  because they are interface, not content.
- **Dark mode is class-based** with a pre-paint inline script, so a dark-mode
  user never sees a white flash. The toggle beats the system preference and
  persists in `localStorage`.
- **`notes` renders exactly as asked**: a collapsed line reading "Some parts
  were unclear (n)", entries small and secondary, never an error state. Per the
  Phase 3 finding, no attempt is made to clean up unreadable fragments — the
  transcript is on the same page to check against.
- **The digest and transcript are on one page**, which is the direct consequence
  of the Phase 3 language bug: a structurally valid digest can still be wrong,
  and the only way a reader catches that is by seeing both.
- **Action item ticks are `localStorage` only**, keyed by token, and the UI says
  so. They are a convenience, not shared state — anyone with the link would
  otherwise see someone else's checkmarks.

### A bug the tests would not have caught

The audio route used `Storage::download()`, which sends
`Content-Disposition: attachment` and no `Accept-Ranges`. Every test passed and
the endpoint returned 200, but an `<audio>` element pointing at it would try to
download the file rather than play it, and could not seek without pulling the
whole thing first.

Found by opening the page and looking at the response headers. Now served with
`response()->file()`: inline disposition, `Accept-Ranges: bytes`, and a Range
request returns `206 Partial Content`. There are tests for all three now.

### Verified in a real browser

Headless run against the actual app: upload page and result page both render
with zero console errors and zero failed requests; a real mp3 was picked,
uploaded through Livewire, submitted, redirected to `/r/{token}`, polled through
the processing steps, and arrived at an Arabic digest. Dark mode at a 390px
viewport has zero horizontal overflow.

---

## Phase 6 decisions

### Horizon was dropped, not forgotten

The original phase list said "rate limits, expiry, cleanup job, Horizon".
**Horizon only supervises Redis queues.** This project moved to the database
queue driver in the 2026-09-19 stack simplification, so there is nothing for it
to attach to — installing it would add a package that could not run.

If `QUEUE_CONNECTION=redis` ever happens, Horizon becomes worth adding at the
same time. Until then `queue:work` plus the `failed_jobs` table is the whole
story, and `php artisan queue:failed` is how you look at it.

### Rate limiting

- **Counted from `usage_logs`, not from the cache.** The rows are already
  written at upload time, so there is one source of truth rather than two things
  that can disagree. It also survives a cache flush — a throttle you can reset
  by restarting the cache is not really a throttle.
- **Only anonymous uploads are throttled.** Signed-in users are identifiable;
  the IP hash exists precisely for people who are not.
- **The refusal says what happened and when to come back**, with the wait
  computed from when the oldest upload in the window falls out of it — not a
  generic "too many requests". It arrives as a validation error on the `file`
  field, so the web form shows it in place like any other upload problem.

### Expiry

- **Enforced when the note is read, not by a sweep.** A note has to stop being
  readable the moment it expires, regardless of when the cleanup job last ran.
  The job reclaims disk on its own schedule; it is not the access control.
- **410 Gone, not 404.** The link was real and has simply passed its retention
  date. Saying so is more useful than pretending it never existed, and the
  response carries no content.
- **The page shows nothing of the note** — no filename, no digest, no
  transcript, no audio. There is a test asserting the transcript does not leak
  through the expired page.

### Cleanup

- `php artisan zbde:purge`, scheduled daily at 03:30, `withoutOverlapping()` and
  `onOneServer()` so it stays sane if this is ever deployed more than once.
  `--dry-run` reports without deleting.
- Deletes **both** files: the original and the normalized wav, for notes where
  the latter still exists.
- **`usage_logs` rows survive, with `voice_note_id` nulled.** The cost history
  outlives the content deliberately — those rows carry a hashed IP, a duration
  and a price, and nothing anybody said. Losing them would also reset the
  throttle count every time the purge ran.

### The normalized wav is deleted as soon as transcription succeeds

It is uncompressed 16kHz PCM, reliably larger than the compressed original it
came from, and it has no purpose once the text exists. The original stays: it is
what the result page plays back and what `duration_seconds` was measured
against. `normalized_duration_seconds` is untouched, so the billing number
outlives the file it was measured from.

**This needed an idempotency guard.** `TranscribeAudio` failed a note when the
normalized file was missing, which after this change is also what a *successful*
re-run looks like. The job now returns early if a transcript already exists, so
a retry cannot fail a note whose transcript is sitting right there — and cannot
pay for a second transcription either. There are tests for both.

### Failure modes

All three are now driven through the real job and read back off the result page:
bad audio, a transcription rejection, and an analysis schema failure that
survives the stricter retry. Each asserts the same two things — a readable
sentence appears, and the provider's own error text does not. The analysis case
additionally asserts the transcript is still shown, because a digest that could
not be validated does not make the transcription worthless.

### Verified live

Schedule registers (`30 3 * * *`). `zbde:purge --dry-run` runs clean against the
real database. An expired note returns 410 from both the JSON endpoint and the
audio route, and its page shows the retention message with no digest.

Note for later: notes created before this phase still have their normalized wav
on disk, since nothing deleted it at the time. The purge job clears them when
they expire.

---

## The design system

Committed to in the Phase 4 design pass. If you change one thing, change it
here rather than adding a second way of doing it.

**Warm, not cool.** "Zbde" is butter, so the accent is an ochre/gold ramp
(`--color-accent-50` through `900` in `resources/css/app.css`) and the neutrals
are `stone`, not `slate`. There is no blue anywhere.

**One accent, spent carefully.** Gold marks only: the gist and the questions on
the result page, a high-urgency badge, the primary button, focus rings, and the
filling rail on the processing view. Everything else is neutral. Spend it in
more places and it stops meaning anything.

**Two typefaces with separate jobs.**

| Face | Used for |
| --- | --- |
| Instrument Serif | page titles, the summary, the questions, the transcript |
| Instrument Sans | everything that is interface: labels, buttons, metadata, eyebrows |

Neither has Arabic glyphs, so RTL content switches to a system Arabic stack
(`--font-arabic`) and gets more line height. That is what `.rtl-content` is for.

**Hierarchy on the result page is deliberate.** The gist is the largest text on
the page and comes first. The questions sit immediately under it in the only
tinted card. The audio player, key details, action items and transcript are all
below and visibly quieter. The filename and metadata are small grey text at the
top, not a heading — the digest is the headline.

**Direction flips content, not interface.** Eyebrows, buttons and the metadata
line stay LTR even on an Arabic note, because they are chrome rather than
something the speaker said.

**Motion is small and purposeful.** The step rail fills over 700ms, step markers
transition over 500ms, newly revealed content rises 6px. There is a
`prefers-reduced-motion` block that turns all of it off.

### Tailwind 4 gotcha, hit twice

`@apply` only takes real utilities, never another custom class. `.card { @apply
surface }` fails the build with `Cannot apply unknown utility class`. The shared
declarations are repeated instead. This bit once in Phase 4 and again in the
design pass despite being written down — so it is now written down twice.

---

## The closing bug sweep

Every flow driven in a real headless browser at 390px: upload, processing, done,
failed, expired, rate limited, and a token that does not exist. **Zero console
errors and zero failed requests on every page**, apart from the deliberate 404.
Zero horizontal overflow everywhere.

Also exercised, all behaving: submitting with no file, a non-audio file, a zero
byte file, refreshing mid-processing, pressing back after an upload, and the
same result open in two tabs (both polled to completion independently).

Audio was verified to actually work rather than merely exist: metadata loads,
playback advances, and seeking to 118s lands at 119s.

Arabic renders RTL and English LTR, each confirmed in both themes, with the
correct font stack in each.

**Two things the sweep found and fixed:**

1. **The 404 page was Laravel's default.** Unstyled white page in the middle of
   a deliberate design. Now there are styled 404, 410, 500 and 503 pages sharing
   one partial.
2. **The urgency badge said only "HIGH"**, which is meaningless to a screen
   reader. It now carries an `sr-only` "urgency" alongside the word.

This is the third time in this project that the only way to find something was
to open the page and look. Worth remembering.

---

## Phase 7, when you come back to it

Nothing here is started. Rough order:

1. **Decide where it runs.** A single small VPS is enough — this is PHP, SQLite
   and ffmpeg. The whole design assumes one box; `onOneServer()` on the schedule
   is the only concession to more.
2. **Server prerequisites:** PHP 8.3 with `curl`, `mbstring`, `openssl`,
   `pdo_sqlite`, `fileinfo`, `zip`; ffmpeg and ffprobe on PATH; a web server
   pointed at `public/`.
3. **Production `.env`:** `APP_ENV=production`, `APP_DEBUG=false`, a fresh
   `APP_KEY`, a real `APP_URL` (the share links are built from it), and the
   OpenAI key. Set `DB_DATABASE` to an absolute path **outside** the web root.
4. **Build step:** `composer install --no-dev --optimize-autoloader`,
   `npm ci && npm run build`, `php artisan migrate --force`, then
   `config:cache`, `route:cache`, `view:cache`. Note that caching config means
   `env()` outside config files stops working — this project already routes
   everything through `config()`, so it should be clean, but verify.
5. **Two long-running processes**, both needing a supervisor (systemd or
   supervisord): `queue:work` — without it nothing is ever processed — and
   `schedule:run` every minute via cron, which is what triggers `zbde:purge`.
   **Every deploy must end with `php artisan queue:restart`.** A running worker
   holds the framework in memory and will keep executing the old code and old
   config indefinitely. This has already caused one wasted debugging session.
6. **HTTPS is not optional.** The clipboard API needs a secure context, so Copy
   digest and Share link silently do nothing over plain HTTP.
7. **Upload size limits in two more places:** `upload_max_filesize` and
   `post_max_size` in `php.ini`, and `client_max_body_size` in nginx. The app
   limit is 100MB; if the server's is lower the user gets a confusing failure
   before Laravel ever sees the request.
8. **Back up the SQLite file.** It is one file, which makes this easy, and
   easy to forget.
9. **Think about cost before it is public.** There is no spend cap — the per-IP
   throttle is the only brake, and `usage_logs` is the only record. A daily
   total query is worth writing.

Optional, deferred earlier, still open: Reverb for live progress with polling as
the fallback, and Docker Compose.

---

## Two bugs found by the user, after everything was "done"

Both were found by uploading a file, not by the suite. Both had passing tests
around them the whole time.

### The 2MB wall

An upload failed with Livewire's generic "The file failed to upload." The file
was 4.68MB; PHP's `upload_max_filesize` was **2M**, left at the
`php.ini-development` default from the Phase 0 toolchain setup. PHP discards the
upload before Laravel sees it, so nothing downstream can produce a better
message.

The page meanwhile advertised "up to 100MB", which was simply untrue.

**Fixed in two places.** `php.ini` raised to 128M/144M (post_max_size must
exceed upload_max_filesize or the POST is rejected first). And
`UploadRules::maxBytes()` now returns the **smallest** of our configured limit,
`upload_max_filesize` and `post_max_size` - that number drives validation, the
error text and the hint under the drop zone. If PHP is ever the tighter
constraint again, the user gets an honest "larger than the 2MB limit" instead of
a mystery.

The irony: this exact trap was already written into the Phase 7 checklist as a
deployment concern. It was never checked locally.

### The prompt language bug

An English voice note came back with an Arabic transcript **and** an Arabic
digest. Whisper had not mis-detected anything - it had *translated* the English
speech into Arabic.

Cause: a Whisper `prompt` is a **language signal**, not just a vocabulary hint.
The mandatory default prompt was entirely Lebanese Arabic vocabulary and was
applied to every upload regardless of language. Arabic prompt in, Arabic text
out; then `language_detected` became `arabic`, which fed the analysis prompt
("the speaker is speaking arabic"), so the digest was Arabic too. One wrong
input, faithfully propagated through three correct steps.

The root error was over-applying a finding. Phase 0 concluded "the prompt is
load-bearing" from a test on Lebanese audio. True for Arabic - and made
mandatory for everything, which broke every other language.

**Fixed:** `config('shoelzbde.transcription.prompts')` is now a map keyed by
language. Selecting Arabic gets the Lebanese vocabulary; auto-detect and every
other language get a short, script-neutral prompt. The never-empty invariant is
unchanged. There are regression tests asserting no non-Arabic language ever
receives Arabic script in its prompt.

**Trade-off worth knowing:** on auto-detect, Lebanese audio no longer gets the
dialect vocabulary, because we cannot know the language before transcribing.
Selecting Arabic in the dropdown is what turns it on, and the form now says so.

### The lesson that cost real money

Two verification runs "failed" after the fix was written. The fix was fine - the
**queue worker had been running since before the change**. `queue:work` boots
the framework once and reuses it for every job, so code and config changes never
reach a running worker.

Roughly $0.08 of API credit was spent proving nothing, and the fix was briefly
believed broken.

**After any change to code or config, restart the worker.** `php artisan
queue:restart` asks running workers to stop after their current job; a
supervisor then starts fresh ones. This belongs in the deploy sequence, not just
in local habit - added to the Phase 7 checklist.

---

## Where the time actually goes

Measured on a 3:00 mp3, per job, with the API calls real:

| Step | Time | |
| --- | --- | --- |
| NormalizeAudio | 987 ms | ffmpeg, local |
| **TranscribeAudio** | **13,657 ms** | the Whisper call, ~70% of the work |
| AnalyzeTranscript | 2,817 ms | the digest |
| NotifyReady | 2,049 ms | a leftover Phase 1 `sleep(2)`, now removed |

Work totalled 19.5s, but wall clock was 40-55s. **The missing half was the
queue worker idling**, not the pipeline. `queue:work` defaults to `--sleep=3`,
and the chain hands off three times, so up to nine seconds went on nothing.

Three changes, no quality cost:

- removed the `sleep(2)` from `NotifyReady` (it does no work - polling is how
  the reader finds out, so there is nothing to send)
- run the worker with `--sleep=1`
- poll interval 3s to 2s, which is how fast a finished note reaches the eye

**End to end: 24.6s, from roughly 40-55s.** Transitions land at 2.1s queued,
4.7s transcribing, 22.5s analyzing, 24.6s done.

What is left is mostly irreducible: ~14s of that 24.6s is the Whisper call
itself. Further gains would have to come from a different model, and would be
a quality decision rather than a plumbing one:

- `gpt-4o-mini-transcribe` is faster than `whisper-1` but returns no segments,
  and is untested on Lebanese dialect - the one thing Phase 0 established is
  that dialect performance cannot be assumed.
- `gpt-4o-mini` for analysis would cut ~2s and most of the cost, at unknown
  cost to digest quality on code-switched Arabic.

Neither should be changed without running the Phase 0 probe against real
dialect audio first.

---

## The prompt echo, and what a Whisper prompt actually is

**The fourth time the only way to find something was to run it.** Found through
the MCP server, on its first real call, by passing names on an Arabic note.

### What happened

An Arabic note with `prompt_hint` came back as a 48 word transcript for three
minutes of speech, and the transcript was the prompt repeating: the same
comma-separated names over and over. The digest built on top of it was
grammatical, confident and entirely fictional - "repeated mention of the
Lebanese Forces, Beirut and the Chouf".

Isolated with one controlled upload each:

| | words |
| --- | --- |
| `ar`, no names | 251, correct |
| `ar` + Arabic names | 48, prompt echo |

The prompt went from 172 to 203 characters. **Thirty-one characters, and it
flipped.** So it was never about length.

### The actual mechanism

A Whisper `prompt` is not an instruction and not a glossary. It is a
**continuation prompt**: the model is told this is the transcript of audio that
came immediately before, and its job is to carry on from it.

The old prompt was `حكي لبناني عامي: شو، هيك، هلق، كتير، ...` - a label followed
by twenty-odd comma-separated words. Read as a transcript, that is *a list in
progress*. The model continued the list. Appending the uploader's names made the
list longer and the pattern more obvious, which is why adding names is what
tipped it over.

This also explains the earlier language bug, which had the same root: an Arabic
prompt is Arabic *preceding speech*, so the natural continuation is more Arabic,
whatever the audio says. Both bugs are the same misunderstanding of what the
parameter is.

### The fix: shape, not length

The prompt now reads like somebody talking, with the dialect markers occurring
naturally inside ordinary sentences rather than being listed. The uploader's
names are folded into a sentence through a per-language template
(`hint_templates` in config, `:names` as the placeholder) instead of being
appended as more list items.

The auto-detect template is deliberately bare - just the names and a full stop.
Any framing words would be a language signal, and on auto-detect the language is
exactly what is not yet known.

Measured after the change, same audio, same baseline of 251 words:

| case | words | vs 251 | prompt overlap | detected |
| --- | --- | --- | --- | --- |
| `ar`, no names | 243 | -8 | 8% | arabic |
| `ar` + Arabic names | 267 | +16 | 9% | arabic |
| `ar` + Latin names | 272 | +21 | 15% | arabic |
| auto detect + names | 253 | +2 | 0% | arabic |

The previously broken case went from 48 words at 75% overlap to 267 at 9%. The
spread of +-21 words across the four is ordinary Whisper run-to-run variance.

### The guard, so it cannot regress quietly

`TranscriptSanityCheck` runs before a transcript is stored, and refuses on two
independent signals:

- **Too sparse for its audio.** Floor of **25 words per minute**. Speech runs
  100-150, a slow halting speaker clears 60, and the echo ran 16. The floor sits
  at a quarter of slow speech so it only ever catches a genuine collapse. Clips
  under 30 seconds are exempt: "call me back" is legitimately three words, and a
  rate is meaningless over so little audio.
- **Too much of it came from the prompt.** Ceiling of **60% word overlap**.
  Measured on real data: a genuine transcript of this audio shares 9% of its
  words with the prompt, the echo shared 75%. The threshold sits in a very wide
  gap, which matters because the prompt is deliberately chosen to contain words
  the speaker will use - some overlap is the point.

Both are config, not constants, and a failure marks the note failed rather than
storing anything. That is the right trade: a failed note says so plainly, while
a wrong transcript produces a digest that looks entirely trustworthy.

### Why the guard is worth more than the fix

The reshaped prompt is a fix for a known failure. The guard is protection
against the *class* of failure, including the ones not yet seen. A prompt echo
is well-formed text of the right language: it passes every structural check
there is, and the schema validation in Phase 3 would happily summarise it. The
only things that give it away are statistical, which is why they are what gets
measured.

---

## The audio player read 0:00 / 0:00

Not the route. `Content-Length`, `Accept-Ranges: bytes`, the inline disposition
and the content type were all already correct, and a Range request already
returned 206.

The cause was `preload="none"` on the `<audio>` element. With it the browser
fetches nothing until play is pressed, so `duration` is `NaN`, the control shows
0:00 / 0:00, and the scrubber does nothing - even though the header above it
shows the real duration, which is read from the database rather than the file.

Now `preload="metadata"`. Verified in a real browser without pressing play:
`readyState` 4, `duration` 180, one seekable range ending at 180, and scrubbing
to 90s worked while still paused.

---

## A process note on editing config/shoelzbde.php

**The same mistake was made twice**, and the second time cost about $0.12 of API
credit and a wasted test run.

Both times the transcription block was replaced by locating a start anchor in
its comment header and an end anchor at `'openai' => [`. The `analysis` block
sits between those two points, so both times it was silently deleted. Nothing
failed loudly: `config('shoelzbde.analysis.driver')` simply returned null, the
`AnalysisService` binding threw, and every note failed at the analysis step
with a message about summarising - which reads like a model problem, not a
config problem.

If this file is edited programmatically again, **anchor on the block being
changed, never on its neighbour**, and afterwards assert that every expected key
still resolves and that both service bindings construct. The test suite catches
it, but only if it is run before spending money on a live test.
