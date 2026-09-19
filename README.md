# Sho el Zbde? 🧈

**Nobody has time for a 9-minute voice note.**

My family sends voice notes that run longer than a phone call. I love them. I
don't have nine minutes. *"Sho el zbde?"* is Lebanese Arabic for "what's the
gist?", which is the only question you have while listening.

This is the app that answers it: drop in an audio file, get back what actually
mattered — a short summary, the questions the sender asked *you*, the dates and
amounts they mentioned, how urgent it sounds, and the full transcript if you want
to check the machine's work.

---

> ### ⚠️ Status: the pipeline runs, the intelligence isn't wired up
>
> Upload works, the job chain runs, and you can watch a note move through every
> status to `done`. But **no AI service is called yet** — the transcription and
> analysis jobs are deliberate placeholders that sleep and advance the status.
> Whisper has been validated separately (see below); connecting it is the next
> phase. Everything documented here is built and tested.

---

## Does Whisper actually handle Lebanese Arabic?

That question decided whether the rest was worth building, so it got answered
first, before any framework.

`transcribe-test.php` is a standalone script — no framework, no composer — that
runs real audio through the same ffmpeg normalization the production pipeline
uses, sends it to Whisper, and reports what came back.

Tested against natural Lebanese dialect speech, the answer was yes, with one
important caveat that shaped the design:

**The prompt is load-bearing.** The same audio transcribed without a prompt
hallucinated an opening phrase and leaked non-Arabic characters into Arabic text.
With a prompt seeded with expected vocabulary, it did neither. So the
transcription step will always send one — there is no unprompted code path.

A second finding: segment boundaries are not stable. The same three minutes
produced 66 segments unprompted and 9 prompted. Segments get stored, but nothing
in the app is allowed to depend on them.

## How it works

```
POST /api/voice-notes                    returns immediately with a token
  │
  ├─ validate: size, type, duration      ← on the ORIGINAL upload, before dispatch
  └─ queue: NormalizeAudio → TranscribeAudio → AnalyzeTranscript → NotifyReady

GET /api/voice-notes/{token}             poll this to watch status advance
```

The upload endpoint never blocks. Every slow step runs in a queued job, and each
job updates the note's status so the result page can follow along:

```
pending → transcribing → analyzing → done
```

...or `failed`, with a readable sentence explaining what went wrong. Exception
text never reaches the user — it can contain file paths, and later, data derived
from private audio.

**Duration is measured twice, on purpose.** `duration_seconds` is the original
upload — that's what the 20-minute limit checks and what you get shown.
`normalized_duration_seconds` is what survived silence trimming and actually got
sent for transcription — that's what gets billed. Conflating them would either
let over-length notes through or charge against the wrong number.

**Result URLs aren't guessable.** The share token is 40 hex characters from a
CSPRNG, and it's the only thing protecting a private message.

## Running it locally

You need exactly two things: **PHP 8.3** and **ffmpeg**. No database server, no
Redis, no Docker.

```bash
composer install
cp .env.example .env
php artisan key:generate

touch database/database.sqlite      # set DB_DATABASE to its absolute path in .env
php artisan migrate
```

Then, in two terminals:

```bash
php artisan serve
php artisan queue:work
```

Upload something:

```bash
curl -X POST http://127.0.0.1:8000/api/voice-notes \
  -H 'Accept: application/json' \
  -F 'file=@note.m4a' -F 'language_hint=ar'
```

That returns a token. Poll it and watch the status move:

```bash
curl http://127.0.0.1:8000/api/voice-notes/<token> -H 'Accept: application/json'
```

Run the tests with `php artisan test`.

### Trying the Whisper probe

Requires an `OPENAI_API_KEY` in `.env`. Drop audio into `samples/` (gitignored,
as is `out/` — real voice notes and their transcripts never get committed):

```bash
php transcribe-test.php samples/note.m4a
php transcribe-test.php --language=ar --prompt="names, places you expect" samples/note.m4a
```

`--save` writes the full response to `out/`. It's opt-in: voice notes are private
messages, so nothing touches disk unless you ask.

## Design rules

Some constraints that are deliberate rather than accidental:

- **Every external AI service sits behind an interface** in `app/Contracts`, with
  implementations in `app/Services`. Swapping hosted Whisper for a self-hosted
  `whisper.cpp` is a binding change, not a rewrite.
- **Nothing assumes SQLite, the database queue driver, or polling.** Those are
  the cheap defaults. `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=redis` and
  broadcasting over Reverb are all config changes, kept open on purpose.
- **Transcripts are never logged.** They're private messages.
- **Language agnostic.** Arabic dialect is the hardest test case, not a special
  code path. The result page will flip to RTL when the detected language calls
  for it.

## Stack

Laravel 12 · PHP 8.3 · SQLite · Livewire · ffmpeg · Whisper

Deliberately boring, and deliberately cheap to start.

## License

Not yet chosen.
