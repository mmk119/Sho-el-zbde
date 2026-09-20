<div class="mx-auto w-full max-w-2xl">
    <div class="mb-10">
        <h1 class="font-serif text-[2.1rem] leading-[1.12] tracking-tight text-sand-900 sm:text-[2.75rem] dark:text-sand-50">
            Nobody has time for a<br class="hidden sm:block"> nine minute voice note.
        </h1>
        <p class="mt-5 max-w-md text-[15px] leading-relaxed text-sand-500 dark:text-sand-400">
            Drop one in and get back the part you actually needed: the gist, what they
            asked you, and the details worth writing down.
        </p>
    </div>

    <form wire:submit="save" class="space-y-7">

        {{-- Drag and drop, with the file input as the fallback underneath. --}}
        <div
            x-data="{
                over: false,
                pick(list) {
                    if (!list || !list.length) return;
                    const input = $refs.input;
                    const dt = new DataTransfer();
                    dt.items.add(list[0]);
                    input.files = dt.files;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                },
            }"
            @dragover.prevent="over = true"
            @dragleave.prevent="over = false"
            @drop.prevent="over = false; pick($event.dataTransfer.files)"
        >
            <label
                for="zbde-file"
                class="flex cursor-pointer flex-col items-center justify-center rounded-[--radius-panel] px-6 py-14 text-center transition-colors duration-200"
                :class="over
                    ? 'bg-accent-100 dark:bg-accent-900/40'
                    : 'bg-sand-100 hover:bg-sand-200/70 dark:bg-sand-900 dark:hover:bg-sand-800/70'"
            >
                <svg class="mb-4 h-6 w-6 text-accent-500 dark:text-accent-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z"/>
                </svg>

                <span class="font-serif text-lg text-sand-800 dark:text-sand-200">Drop a voice note here</span>
                <span class="hint mt-1.5">or tap to choose a file</span>

                <input
                    id="zbde-file"
                    x-ref="input"
                    type="file"
                    wire:model="file"
                    accept="{{ \App\Support\UploadRules::acceptAttribute() }}"
                    class="sr-only"
                >
            </label>

            {{-- Upload progress: Livewire fires these on the file input. --}}
            <div wire:loading wire:target="file" class="mt-4">
                <div class="h-0.5 w-full overflow-hidden rounded-full bg-sand-200 dark:bg-sand-800">
                    <div class="h-full w-1/3 animate-pulse rounded-full bg-accent-400"></div>
                </div>
                <p class="hint mt-2">Uploading…</p>
            </div>

            @if ($file && ! $errors->has('file'))
                <div class="animate-rise mt-4 flex items-center gap-2 text-sm" wire:loading.remove wire:target="file">
                    <svg class="h-4 w-4 shrink-0 text-accent-500 dark:text-accent-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                    </svg>
                    <span class="truncate text-sand-700 dark:text-sand-300">{{ $file->getClientOriginalName() }}</span>
                    <span class="hint shrink-0">{{ number_format($file->getSize() / 1048576, 1) }} MB</span>
                </div>
            @endif

            @error('file')
                <p class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm leading-relaxed text-red-700 dark:bg-red-950/40 dark:text-red-300">
                    {{ $message }}
                </p>
            @enderror

            <p class="hint mt-4">
                {{ implode(', ', \App\Support\UploadRules::friendlyFormats()) }}
                · up to {{ \App\Support\UploadRules::maxMegabytes() }}MB
                · up to {{ \App\Support\UploadRules::maxDurationMinutes() }} minutes
            </p>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <label for="lang" class="label">Language</label>
                <select id="lang" wire:model="language_hint" class="field">
                    @foreach ($languages as $code => $name)
                        <option value="{{ $code }}">{{ $name }}</option>
                    @endforeach
                </select>
                <p class="hint mt-2">
                    Auto detect works well. Picking the language is what unlocks the
                    dialect vocabulary for Arabic.
                </p>
            </div>

            <div>
                <label for="hint" class="label">
                    Names and places <span class="font-normal text-sand-400">optional</span>
                </label>
                <input
                    id="hint"
                    type="text"
                    wire:model="prompt_hint"
                    class="field"
                    maxlength="500"
                    placeholder="Teta Mariam, Jounieh"
                >
                <p class="hint mt-2">Helps get proper nouns right, and it's the single biggest win.</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-4 pt-2">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save, file">
                <span wire:loading.remove wire:target="save">Get the gist</span>
                <span wire:loading wire:target="save">Starting…</span>
            </button>
            <span class="hint" wire:loading.remove wire:target="save">Usually under a minute.</span>
        </div>
    </form>
</div>
