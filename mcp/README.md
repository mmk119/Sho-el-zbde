# Sho el Zbde MCP server

Lets Claude Desktop send a voice note to a running [Sho el Zbde](../README.md)
instance and read back the digest.

It is a **client of the app's HTTP API and nothing more**. It holds no API keys
and does no transcription — the Laravel app holds the OpenAI credentials and
does all the work. Nothing in here touches the Laravel code.

---

## What you get

| Tool | |
| --- | --- |
| `submit_voice_note` | Uploads a local audio file. Returns a token immediately. |
| `get_digest` | Reads the digest back for a token. |

`submit_voice_note` returns **before** the note is ready, because the app
processes in a background queue. Call `get_digest` a few seconds later. While
it's still working, `get_digest` says so plainly — that's a normal answer, not
an error.

**The transcript is withheld by default.** It's the private content of
somebody's voice note and it's long, so `get_digest` returns the digest alone
unless you pass `include_transcript: true`.

---

## Prerequisites

The Laravel app must be **running**, with **both** processes:

```bash
php artisan serve
php artisan queue:work --sleep=1
```

Without the queue worker an upload is accepted but never processed, and
`get_digest` will report "still working" forever.

You also need **Node 20 or newer** (`node -v`).

## Build it

```bash
cd mcp
npm install
npm run build
```

That produces `dist/index.js`, which is what Claude Desktop runs.

---

## Registering it with Claude Desktop

Edit the Claude Desktop config file:

- **Windows** — `%APPDATA%\Claude\claude_desktop_config.json`
- **macOS** — `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Linux** — `~/.config/Claude/claude_desktop_config.json`

Add a `sho-el-zbde` entry under `mcpServers`. **Use an absolute path** — Claude
Desktop does not run from your project directory.

### Windows

Backslashes must be doubled in JSON:

```json
{
  "mcpServers": {
    "sho-el-zbde": {
      "command": "node",
      "args": ["C:\\Users\\Dell\\Desktop\\Sho-el-zbde\\mcp\\dist\\index.js"],
      "env": {
        "SHO_EL_ZBDE_URL": "http://localhost:8000"
      }
    }
  }
}
```

### macOS / Linux

```json
{
  "mcpServers": {
    "sho-el-zbde": {
      "command": "node",
      "args": ["/absolute/path/to/sho-el-zbde/mcp/dist/index.js"],
      "env": {
        "SHO_EL_ZBDE_URL": "http://localhost:8000"
      }
    }
  }
}
```

Then **quit Claude Desktop completely and reopen it** — it only reads this file
at startup. The two tools should appear in the tools list.

### Environment variables

| Variable | Default | |
| --- | --- | --- |
| `SHO_EL_ZBDE_URL` | `http://localhost:8000` | Where the Laravel app is listening. No trailing slash needed. |
| `SHO_EL_ZBDE_MAX_UPLOAD_MB` | `100` | Only used to reject oversized files early. The API enforces the real limit. |

There is deliberately no API key here. If you ever find yourself adding one,
something has gone wrong — the credentials belong to the Laravel app.

---

## Using it

Once registered, ask Claude things like:

> Summarise the voice note at `C:\Users\Dell\Downloads\teta.m4a` — it's in
> Lebanese Arabic and mentions Teta Mariam and Jounieh.

Claude will call `submit_voice_note` with `language_hint: "ar"` and
`names: "Teta Mariam, Jounieh"`, then `get_digest` until it's ready.

### `submit_voice_note`

| Input | | |
| --- | --- | --- |
| `file_path` | required | Absolute path to the audio file |
| `language_hint` | optional | ISO-639-1 code, e.g. `ar`, `en`. Omit for auto detect. |
| `names` | optional | Names and places expected in the recording |

Accepted formats: mp3, m4a, wav, ogg, opus, aac, flac, amr, webm, mp4, 3gp.
Up to 100MB and 20 minutes.

`names` feeds the app's `prompt_hint`, which improves proper-noun accuracy more
than any other input. `language_hint: "ar"` is what enables the Lebanese dialect
vocabulary — auto detect uses a neutral prompt.

### `get_digest`

| Input | | |
| --- | --- | --- |
| `token` | required | The token from `submit_voice_note` |
| `include_transcript` | optional, default `false` | Also return the full transcript |

Returns the summary, the questions the speaker asked the listener, key details
(dates, times, amounts, names, places), things to do, the urgency, and a note of
any passages that were unclear.

---

## When something goes wrong

The server tries to answer with a sentence you can act on rather than a stack
trace.

| What you see | What it means |
| --- | --- |
| "Could not reach Sho el Zbde at …" | The app isn't running, or it's on another port. Start it, or set `SHO_EL_ZBDE_URL`. |
| "There is no file at …" | Wrong path. Checked locally, before anything is uploaded. |
| ".pdf is not an audio format this app reads" | Wrong file type, caught locally. |
| "This note is 21 minutes long. The limit is 20 minutes." | The app's own refusal, passed through as-is. |
| "No voice note matches the token …" | Truncated token, or the note was deleted. |
| "…has passed its 30 day retention window" | Expired. The audio and digest are gone. |
| Stuck on "still working" | The queue worker isn't running. |

---

## Project layout

```
mcp/
├── src/
│   ├── index.ts            server setup, stdio transport
│   ├── api.ts              HTTP client for the Laravel API
│   ├── validation.ts       local pre-flight before uploading
│   ├── format.ts           digest → readable prose
│   ├── errors.ts           typed failures, each with its own sentence
│   ├── constants.ts        base URL, limits
│   ├── types.ts            API response shapes
│   └── tools/
│       ├── submitVoiceNote.ts
│       └── getDigest.ts
└── dist/                   built output; dist/index.js is the entry point
```

**Validation happens twice, on purpose.** The local pre-flight catches the
obvious mistakes — missing file, wrong extension, empty file — so you get an
answer instantly instead of after pushing bytes across the wire. The API stays
the authority on everything else, and its refusals are passed through word for
word rather than reworded, so there's only one place those sentences are
written.

## Rebuilding after a change

```bash
npm run build
```

Then restart Claude Desktop. It runs `dist/index.js` as a subprocess and holds
it for the life of the app, so a rebuild alone won't take effect.
