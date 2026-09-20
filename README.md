# Sho el Zbde? 🧈

**Nobody has time for a nine minute voice note.**

Drop one in and get back the part you actually needed: a short summary, the
questions the sender directed at *you*, the dates and amounts they mentioned,
how urgent it sounds — and the full transcript, in case the machine got
something wrong.

*Sho el zbde?* is Lebanese Arabic for "what's the gist?", which is the only
question you have while a long voice note plays.

---

## Why

My family sends voice notes that run longer than a phone call. I love them. I
don't have nine minutes, and skimming isn't possible — audio makes you listen at
the speed it was spoken.

The hard part isn't summarizing. It's that speech recognition on Lebanese Arabic
is genuinely difficult: it isn't Modern Standard Arabic, it's full of French and
English loanwords, and models see far more MSA than anything my aunt says. So
that question got answered first, before any framework existed, with a
throwaway script. The answer was yes — with caveats that shaped everything
after.

## What it does

```
POST /api/voice-notes  or the upload page
  │
  ├─ validate size, type and duration      ← on the original upload, before dispatch
  └─ queue: NormalizeAudio → TranscribeAudio → AnalyzeTranscript → NotifyReady

/r/{token}    watch it work, then read the digest
```

The upload returns immediately. Every slow step runs in a queued job, and the
result page polls until it's done:

```
pending → transcribing → analyzing → done          (or failed, with a reason)
```

**The digest and the transcript are on the same page**, deliberately. A digest
can be confidently wrong, and the transcript is how you catch it.

### What comes back

| | |
| --- | --- |
| **El Zbde** | the gist, in three to five bullets |
| **Questions for you** | what they actually asked, highlighted |
| **Key details** | dates, times, amounts, names, places |
| **Things to do** | a checklist, ticks saved in your browser |
| **Unclear parts** | a quiet line when the model couldn't make something out |
| **Full transcript** | collapsed, with a copy button |

The page flips to right-to-left when the detected language calls for it,
follows your system dark mode with a manual toggle, and is built mobile first.

## The thing worth knowing

**The transcription prompt is load-bearing.** The same audio, transcribed
without a prompt, invented an opening phrase and leaked non-Arabic characters
into Arabic text. With a prompt seeded with expected vocabulary, it did neither.
So there is no code path that transcribes without one — the interface has no
parameter that could express it.

A related finding: the analysis step is told, in the prompt, that it's reading
machine output rather than a document, and that errors cluster on proper nouns
and code-switched words. Where a garbled word is recoverable from context it
recovers it; where it isn't, it says so in `notes` instead of guessing. **A
wrong name is worse than an admitted gap.**

---

## Running it locally

You need **PHP 8.3** and **ffmpeg**. No database server, no Redis, no Docker.

### Prerequisites

**PHP 8.3** with these extensions: `curl`, `mbstring`, `openssl`, `pdo_sqlite`,
`fileinfo`, `zip`. Check with `php -m`.

**ffmpeg and ffprobe** on your `PATH` — both, not just ffmpeg:

```bash
ffmpeg -version && ffprobe -version
```

macOS `brew install ffmpeg` · Debian/Ubuntu `sudo apt install ffmpeg` · Windows
download a build from [gyan.dev](https://www.gyan.dev/ffmpeg/builds/) and add
its `bin` folder to your PATH.

**Composer**, and **Node 20+** (Node only to build the CSS once).

**An OpenAI API key** — used for both transcription and the digest.

### Setup

```bash
git clone <your-fork-url> sho-el-zbde
cd sho-el-zbde

composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate
```

Now open `.env` and set two things:

```dotenv
OPENAI_API_KEY=sk-...

# An ABSOLUTE path. A relative one will fail to open.
DB_DATABASE=/absolute/path/to/sho-el-zbde/database/database.sqlite
```

Create the database file and run the migrations:

```bash
touch database/database.sqlite     # Windows: type nul > database\database.sqlite
php artisan migrate
```

### Run it

Two processes, in two terminals:

```bash
php artisan serve
```

```bash
php artisan queue:work
```

**Both are required.** The upload returns instantly by design, so without a
worker your note sits at `pending` forever.

Open <http://127.0.0.1:8000> and drop in a voice note.

### Windows: one extra step

Windows PHP builds ship no CA bundle, so every HTTPS call fails with
`unable to get local issuer certificate`. Download
[cacert.pem](https://curl.se/ca/cacert.pem) and point `php.ini` at it:

```ini
curl.cainfo = "C:\path\to\cacert.pem"
openssl.cafile = "C:\path\to\cacert.pem"
```

### Tests

```bash
php artisan test
```

169 tests. Five drive the real ffmpeg binary; the rest fake every external
service, so the suite makes no network calls and costs nothing to run.

---

## Using the API instead

The web UI and the API share the same intake path, so either works:

```bash
curl -X POST http://127.0.0.1:8000/api/voice-notes \
  -H 'Accept: application/json' \
  -F 'file=@note.m4a' \
  -F 'language_hint=ar' \
  -F 'prompt_hint=Teta Mariam, Jounieh'
```

Returns `{"token": "...", "status": "pending"}` immediately. Poll it:

```bash
curl http://127.0.0.1:8000/api/voice-notes/<token> -H 'Accept: application/json'
```

`prompt_hint` is optional but it's the single biggest quality win — names and
places you expect to hear.

### Trying Whisper on its own

`transcribe-test.php` needs no framework and no database. Drop audio into
`samples/` (gitignored, as is `out/` — real voice notes never get committed):

```bash
php transcribe-test.php samples/note.m4a
php transcribe-test.php --language=ar --prompt="names you expect" samples/note.m4a
```

Add `--save` to write the full response to `out/`. It's opt-in: voice notes are
private, so nothing touches disk unless you ask.

---

## Keeping it safe to run

**Anonymous uploads are rate limited per IP**, counted from the usage rows the
app already writes rather than a separate cache tally. Hitting the limit gets a
sentence saying how many you've sent and when to try again. Only a hashed IP is
ever stored.

**Notes expire.** After the retention window the page says so plainly and the
API returns `410 Gone`. That's enforced when the note is read, not by waiting
for a cleanup job.

**A scheduled job deletes the audio** — both the original and the normalized
copy — along with the transcript and digest:

```bash
php artisan zbde:purge --dry-run
php artisan zbde:purge
```

It runs daily from the scheduler. Usage rows survive with their link to the note
removed, so cost history outlives the content without keeping any of it.

**The normalized audio is deleted as soon as transcription succeeds.** It's
uncompressed 16kHz PCM, larger than the original, and useless once the text
exists. The original is kept so you can always play it back and check.

**Transcripts are never logged.** They're private messages.

---

## Configuration

Everything lives in `config/shoelzbde.php`, reading from `.env`:

| Variable | Default | |
| --- | --- | --- |
| `MAX_UPLOAD_MB` | `100` | checked against the original upload |
| `MAX_DURATION_SECONDS` | `1200` | 20 minutes, also on the original |
| `RETENTION_DAYS` | `30` | before a note expires and is purged |
| `ANON_UPLOADS_PER_HOUR` | `5` | per IP |
| `TRANSCRIPTION_MODEL` | `whisper-1` | |
| `TRANSCRIPTION_PROMPT` | Lebanese default | blank falls back to the built-in |
| `ANALYSIS_MODEL` | `gpt-4o` | |
| `POLL_INTERVAL_SECONDS` | `3` | how often the result page checks |

## How it's built

Every external AI service sits behind an interface in `app/Contracts`, with
implementations in `app/Services`. Swapping hosted Whisper for a self-hosted
`whisper.cpp`, or OpenAI for another provider, is a binding change rather than a
rewrite.

Nothing assumes SQLite, the database queue driver, or polling — those are the
cheap defaults. `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=redis` and broadcasting
over WebSockets are all config changes, kept open on purpose.

Two durations are recorded because they differ: `duration_seconds` is the
original upload, which is what the limit checks and what you're shown.
`normalized_duration_seconds` is what survived silence trimming and actually got
sent — which is what you're billed on.

**Stack:** Laravel 13 · PHP 8.3 · SQLite · Livewire · Tailwind · ffmpeg ·
Whisper · an LLM for the digest.

Deliberately boring, and deliberately cheap to start.

## Status

Working locally. Deployment configuration is the one thing not built — no
Dockerfile, deploy script or CI here yet.

## License

Not yet chosen.
