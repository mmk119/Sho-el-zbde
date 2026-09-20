@php
    use App\Enums\VoiceNoteStatus;

    $digest = $note->digest;
    $transcript = $note->transcript;
    $dir = $this->isRtl() ? 'rtl' : 'ltr';

    $urgencyStyles = [
        'high' => 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
        'normal' => 'bg-stone-200 text-stone-700 dark:bg-stone-800 dark:text-stone-300',
        'low' => 'bg-stone-100 text-stone-500 dark:bg-stone-800/60 dark:text-stone-400',
    ];

    $entityLabels = [
        'dates' => 'Dates',
        'times' => 'Times',
        'amounts' => 'Amounts',
        'names' => 'Names',
        'places' => 'Places',
    ];
@endphp

<div
    @if ($this->isWorking())
        wire:poll.{{ config('shoelzbde.poll_interval_seconds') }}s="refreshNote"
    @endif
>

    {{-- ─────────────────────────────  PROCESSING  ───────────────────────────── --}}
    @if ($note->status !== VoiceNoteStatus::Done && $note->status !== VoiceNoteStatus::Failed)

        <div class="mb-6">
            <h1 class="text-xl font-semibold tracking-tight">Working on it</h1>
            <p class="hint mt-1 truncate">{{ $note->original_filename }}</p>
        </div>

        <ol class="card space-y-1">
            @foreach ($this->steps() as $step)
                <li class="flex items-start gap-3 py-2.5">
                    <span class="mt-0.5 shrink-0">
                        @if ($step['state'] === 'complete')
                            <svg class="h-5 w-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                            </svg>
                        @elseif ($step['state'] === 'active')
                            <svg class="h-5 w-5 animate-spin text-stone-500" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                                <path class="opacity-90" fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7V2Z"/>
                            </svg>
                        @else
                            <span class="block h-5 w-5 rounded-full border-2 border-stone-200 dark:border-stone-700"></span>
                        @endif
                    </span>

                    <span class="min-w-0">
                        <span class="block text-sm font-medium {{ $step['state'] === 'pending' ? 'text-stone-400 dark:text-stone-500' : '' }}">
                            {{ $step['label'] }}
                        </span>
                        @if ($step['state'] === 'active')
                            <span class="hint">{{ $step['note'] }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>

        <p class="hint mt-4" wire:key="elapsed">
            {{ $this->elapsedSeconds() }}s elapsed · this page updates itself
        </p>

    {{-- ──────────────────────────────  FAILED  ─────────────────────────────── --}}
    @elseif ($note->status === VoiceNoteStatus::Failed)

        <div class="mb-6">
            <h1 class="text-xl font-semibold tracking-tight">This one didn't work out</h1>
            <p class="hint mt-1 truncate">{{ $note->original_filename }}</p>
        </div>

        <div class="card border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40">
            <p class="text-sm text-amber-900 dark:text-amber-200">
                {{ $note->error_message ?? 'Something went wrong while processing this note.' }}
            </p>
        </div>

        {{-- A failed digest does not mean a lost transcript. --}}
        @if ($transcript)
            <div class="mt-5" dir="{{ $dir }}">
                <h2 class="mb-2 text-sm font-semibold text-stone-500 dark:text-stone-400">Transcript</h2>
                <div class="card">
                    <p class="text-[15px] leading-relaxed whitespace-pre-wrap">{{ $transcript->full_text }}</p>
                </div>
            </div>
        @endif

        <a href="{{ route('upload') }}" wire:navigate class="btn-quiet mt-5">Try another one</a>

    {{-- ──────────────────────────────  RESULT  ─────────────────────────────── --}}
    @else

        {{-- Header: the facts about the note itself, always LTR. --}}
        <div class="mb-6">
            <div class="mb-2 flex flex-wrap items-center gap-2">
                @if ($digest)
                    <span class="chip {{ $urgencyStyles[$digest->urgency->value] }} font-medium">
                        @if ($digest->urgency->value === 'high')
                            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                <path d="M10 2a1 1 0 0 1 1 1v8a1 1 0 1 1-2 0V3a1 1 0 0 1 1-1Zm0 13.5a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5Z"/>
                            </svg>
                        @endif
                        {{ ucfirst($digest->urgency->value) }} urgency
                    </span>
                @endif
            </div>

            <h1 class="text-xl font-semibold tracking-tight break-words">{{ $note->original_filename }}</h1>

            <p class="hint mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                @if ($note->language_detected)
                    <span>{{ ucfirst($note->language_detected) }}</span>
                    <span aria-hidden="true">·</span>
                @endif
                @if ($note->duration_seconds)
                    <span>{{ intdiv($note->duration_seconds, 60) }}:{{ str_pad($note->duration_seconds % 60, 2, '0', STR_PAD_LEFT) }}</span>
                    <span aria-hidden="true">·</span>
                @endif
                @if ($transcript)
                    <span>{{ number_format($transcript->word_count) }} words</span>
                @endif
            </p>
        </div>

        {{-- The original audio, so the reader can always check the machine's work. --}}
        <audio controls preload="none" class="mb-6 w-full" src="{{ route('voice-notes.audio', $note->public_token) }}">
            Your browser can't play audio.
        </audio>

        @if ($digest)
            <div dir="{{ $dir }}" class="space-y-5">

                {{-- El Zbde --}}
                <section class="card">
                    <h2 class="mb-3 text-sm font-semibold tracking-wide text-stone-500 uppercase dark:text-stone-400">
                        El Zbde
                    </h2>
                    <ul class="space-y-2.5">
                        @foreach ($digest->summary as $line)
                            <li class="flex gap-2.5 text-[15px] leading-relaxed">
                                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-stone-400"></span>
                                <span>{{ $line }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{-- Questions: the thing people actually open this for. --}}
                <section class="card border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/40">
                    <h2 class="mb-3 text-sm font-semibold tracking-wide text-sky-800 uppercase dark:text-sky-300">
                        Questions for you
                    </h2>

                    @if (count($digest->questions))
                        <ul class="space-y-3">
                            @foreach ($digest->questions as $question)
                                <li class="text-[15px] leading-relaxed font-medium text-sky-950 dark:text-sky-100">
                                    {{ $question }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-sky-800/70 dark:text-sky-300/70" dir="ltr">
                            Nothing to answer — they didn't ask you anything directly.
                        </p>
                    @endif
                </section>

                {{-- Key details --}}
                @php
                    $entities = collect($digest->entities ?? [])->filter(fn ($v) => is_array($v) && count($v));
                @endphp

                @if ($entities->isNotEmpty())
                    <section class="card">
                        <h2 class="mb-3 text-sm font-semibold tracking-wide text-stone-500 uppercase dark:text-stone-400">
                            Key details
                        </h2>
                        <div class="space-y-3">
                            @foreach ($entities as $type => $values)
                                <div>
                                    <p class="hint mb-1.5" dir="ltr">{{ $entityLabels[$type] ?? ucfirst($type) }}</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($values as $value)
                                            <span class="chip">{{ $value }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Action items: a local checklist, kept in this browser only. --}}
                @if (count($digest->action_items))
                    <section
                        class="card"
                        x-data="{
                            key: 'zbde-todo-{{ $note->public_token }}',
                            done: {},
                            init() {
                                try { this.done = JSON.parse(localStorage.getItem(this.key)) || {}; } catch (e) { this.done = {}; }
                            },
                            toggle(i) {
                                this.done[i] = !this.done[i];
                                try { localStorage.setItem(this.key, JSON.stringify(this.done)); } catch (e) {}
                            },
                        }"
                    >
                        <h2 class="mb-3 text-sm font-semibold tracking-wide text-stone-500 uppercase dark:text-stone-400">
                            Things to do
                        </h2>
                        <ul class="space-y-2.5">
                            @foreach ($digest->action_items as $i => $item)
                                <li>
                                    <label class="flex cursor-pointer items-start gap-3">
                                        <input
                                            type="checkbox"
                                            class="mt-1 h-4 w-4 shrink-0 rounded border-stone-300 text-stone-900 focus:ring-stone-400 dark:border-stone-600 dark:bg-stone-800"
                                            :checked="done[{{ $i }}]"
                                            @change="toggle({{ $i }})"
                                        >
                                        <span class="text-[15px] leading-relaxed" :class="done[{{ $i }}] && 'line-through text-stone-400 dark:text-stone-500'">
                                            {{ $item }}
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                        <p class="hint mt-3" dir="ltr">Ticks are saved in this browser only.</p>
                    </section>
                @endif

                {{--
                    Unclear passages. Deliberately quiet: small, secondary, never an
                    error state. A digest with notes is still a good digest, and the
                    transcript is right there to check against.
                --}}
                @if (count($digest->notes ?? []))
                    <details class="group px-1">
                        <summary class="hint flex cursor-pointer list-none items-center gap-1.5" dir="ltr">
                            <svg class="h-3.5 w-3.5 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
                            </svg>
                            Some parts were unclear ({{ count($digest->notes) }})
                        </summary>
                        <ul class="mt-2 space-y-1 ps-5">
                            @foreach ($digest->notes as $unclear)
                                <li class="text-xs break-words text-stone-400 dark:text-stone-500">{{ $unclear }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                {{-- Transcript, collapsed. On the same page as the digest on purpose. --}}
                @if ($transcript)
                    <section
                        class="card"
                        x-data="{
                            open: false,
                            copied: false,
                            copy() {
                                navigator.clipboard.writeText($refs.body.innerText).then(() => {
                                    this.copied = true;
                                    setTimeout(() => this.copied = false, 1800);
                                });
                            },
                        }"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <button type="button" @click="open = !open" class="flex items-center gap-1.5 text-sm font-semibold tracking-wide text-stone-500 uppercase dark:text-stone-400" dir="ltr">
                                <svg class="h-3.5 w-3.5 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
                                </svg>
                                Full transcript
                            </button>

                            <button type="button" @click="copy()" class="btn-quiet px-2.5 py-1.5 text-xs" dir="ltr">
                                <span x-show="!copied">Copy</span>
                                <span x-show="copied" x-cloak class="text-emerald-600">Copied</span>
                            </button>
                        </div>

                        <div x-show="open" x-cloak class="mt-4">
                            <p x-ref="body" class="text-[15px] leading-relaxed whitespace-pre-wrap">{{ $transcript->full_text }}</p>
                        </div>
                    </section>
                @endif
            </div>

            {{-- Actions on the digest itself. Always LTR - these are UI, not content. --}}
            <div
                class="mt-6 flex flex-wrap gap-2"
                x-data="{
                    copiedDigest: false,
                    copiedLink: false,
                    copyDigest() {
                        navigator.clipboard.writeText(@js($this->plainTextDigest())).then(() => {
                            this.copiedDigest = true;
                            setTimeout(() => this.copiedDigest = false, 1800);
                        });
                    },
                    copyLink() {
                        navigator.clipboard.writeText(window.location.href).then(() => {
                            this.copiedLink = true;
                            setTimeout(() => this.copiedLink = false, 1800);
                        });
                    },
                }"
            >
                <button type="button" @click="copyDigest()" class="btn-quiet">
                    <span x-show="!copiedDigest">Copy digest</span>
                    <span x-show="copiedDigest" x-cloak class="text-emerald-600">Copied</span>
                </button>

                <button type="button" @click="copyLink()" class="btn-quiet">
                    <span x-show="!copiedLink">Share link</span>
                    <span x-show="copiedLink" x-cloak class="text-emerald-600">Link copied</span>
                </button>

                <a href="{{ route('upload') }}" wire:navigate class="btn-quiet">Another one</a>
            </div>
        @else
            <div class="card">
                <p class="text-sm text-stone-600 dark:text-stone-400">
                    This note finished without a digest. The transcript is below if there is one.
                </p>
            </div>

            @if ($transcript)
                <div class="mt-5" dir="{{ $dir }}">
                    <div class="card">
                        <p class="text-[15px] leading-relaxed whitespace-pre-wrap">{{ $transcript->full_text }}</p>
                    </div>
                </div>
            @endif
        @endif
    @endif
</div>
