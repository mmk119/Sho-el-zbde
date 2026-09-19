# Sho el Zbde? 🧈

**Nobody has time for a 9-minute voice note.**

Drop in an audio file and get back what actually matters: a short summary,
the questions the sender asked you, any dates or amounts they mentioned,
and how urgent it sounds. The full transcript is there too if you want to check.

Works in most languages, including Arabic dialect.

---

### Why

My family sends voice notes that run longer than a phone call. I love them.
I don't have nine minutes. "Sho el zbde?" is Lebanese for "what's the gist?",
which is the only question you have while listening.

### How it works

Upload → ffmpeg normalizes the audio → Whisper transcribes it →
an LLM pulls out the structure → you get the digest in under a minute.

All the heavy work runs in queued jobs, so the upload returns instantly
and you watch the progress live.

### Stack

Laravel 12 · MySQL · Redis · Horizon · Reverb · Whisper · Docker
