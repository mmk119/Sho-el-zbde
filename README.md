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

> ### ⚠️ Status: early
>
> This repo is at **Phase 0**. The only thing here is a standalone script that
> measures how well Whisper handles real Lebanese Arabic voice notes — the
> question that decides whether the rest is worth building. There is no Laravel
> app yet. Everything below describes what exists today; the README grows as
> features become real.

---

## Why start with a test script

Arabic dialect is the hard case. Lebanese is not Modern Standard Arabic, it's
full of French and English loanwords, and speech recognition models are trained
on far more MSA than anything my aunt says. If Whisper can't produce a usable
transcript of a real voice note, no amount of clever summarization downstream
saves it.

So before any framework, any database, any queue: measure it.

`transcribe-test.php` takes real audio, runs it through the same normalization
the production pipeline will use, sends it to Whisper, and reports what came
back — detected language, wall time against realtime, word and segment counts,
rough cost, and the transcript itself.

## Running the Phase 0 probe

**You need:** PHP 8.3 with the `curl` extension, and `ffmpeg` on your PATH.
The script runs without ffmpeg but sends the original file unmodified, which
tells you less.

```bash
cp .env.example .env
# set OPENAI_API_KEY in .env
```

Drop some real voice notes into `samples/` (gitignored — audio and transcripts
never get committed), then:

```bash
php transcribe-test.php samples/teta.m4a
```

```
========================================================================
  teta.m4a
========================================================================
  source     4.2 MB  ·  9:07
  normalized 8.7 MB  ·  16000Hz mono  ·  7:41 after silence trim  ·  2.1s
  model      whisper-1  ·  language hint: auto detect
  ...

  detected   arabic  (RTL - result page must flip direction)
  took       18.4s  (25.1x realtime)
  words      1247  ·  segments: 168
  cost       ~$0.0461
```

Useful flags:

| Flag | What it does |
| --- | --- |
| `--language=ar` | Skip auto detection and force a language hint |
| `--model=NAME` | Try a different transcription model |
| `--prompt="..."` | Nudge Whisper on spellings of names and places |
| `--no-normalize` | Send the original file, skipping ffmpeg |
| `--save` | Write the full JSON response to `out/` |
| `--quiet` | Print only the transcript |

`--save` is opt-in on purpose. Voice notes are private messages; nothing is
written to disk unless you ask for it.

## Where it's going

The shape of the thing, once built: an upload returns immediately with a token,
and the slow work — normalize, transcribe, analyze — happens in queued jobs while
the page watches progress live. Every AI service sits behind an interface, so
swapping hosted Whisper for a self-hosted `whisper.cpp` is a one-line change.

**Planned stack:** Laravel 12 · PHP 8.3 · SQLite · Livewire · ffmpeg · Whisper.

Deliberately boring, and deliberately cheap to start: SQLite instead of a
database server, Laravel's database queue driver instead of Redis, polling
instead of WebSockets. Running it locally should need PHP and ffmpeg and nothing
else. Swapping in MySQL, Redis or Reverb later is a config change, not a
rewrite.

## License

Not yet chosen.
