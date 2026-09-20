<?php

namespace App\Livewire;

use App\Enums\VoiceNoteStatus;
use App\Models\VoiceNote;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * One component for both the processing view and the result, because they are
 * the same URL at different moments. Polls only while there is something to
 * wait for.
 */
class ViewNote extends Component
{
    public VoiceNote $note;

    public function mount(VoiceNote $note): void
    {
        $this->note = $note;
    }

    /** Polling target. Stops mattering once the note is terminal. */
    public function refreshNote(): void
    {
        $this->note->refresh();
    }

    public function isWorking(): bool
    {
        return ! $this->note->status->isTerminal();
    }

    #[Computed]
    public function elapsedSeconds(): int
    {
        return max(0, (int) $this->note->created_at->diffInSeconds(now()));
    }

    /**
     * The four pipeline steps as the reader sees them, each resolved to
     * pending / active / complete / failed.
     *
     * Derived from status rather than stored, so a note that was processed
     * before this page existed still renders correctly.
     *
     * @return array<int, array{label: string, note: string, state: string}>
     */
    #[Computed]
    public function steps(): array
    {
        $status = $this->note->status;
        $failed = $status === VoiceNoteStatus::Failed;

        // How far the pipeline got, as an index into the four steps.
        $reached = match ($status) {
            VoiceNoteStatus::Pending => 0,
            VoiceNoteStatus::Transcribing => 1,
            VoiceNoteStatus::Analyzing => 2,
            VoiceNoteStatus::Done => 4,
            VoiceNoteStatus::Failed => $this->failedAt(),
        };

        $labels = [
            ['Preparing audio', 'Converting and trimming silence'],
            ['Transcribing', 'Turning speech into text'],
            ['Finding the gist', 'Summarising what mattered'],
            ['Almost done', 'Putting your digest together'],
        ];

        $steps = [];

        foreach ($labels as $i => [$label, $hint]) {
            $steps[] = [
                'label' => $label,
                'note' => $hint,
                'state' => match (true) {
                    $failed && $i === $reached => 'failed',
                    $i < $reached => 'complete',
                    $i === $reached => 'active',
                    default => 'pending',
                },
            ];
        }

        return $steps;
    }

    /** Best guess at which step failed, from what exists in the database. */
    private function failedAt(): int
    {
        if ($this->note->digest !== null) {
            return 3;
        }

        if ($this->note->transcript !== null) {
            return 2;
        }

        return $this->note->normalized_duration_seconds !== null ? 1 : 0;
    }

    /**
     * Whether to flip the digest and transcript to right-to-left. Driven by the
     * detected language, never by a per-language code path.
     */
    #[Computed]
    public function isRtl(): bool
    {
        $rtl = ['arabic', 'hebrew', 'persian', 'urdu', 'pashto', 'kurdish', 'yiddish', 'divehi', 'sindhi'];
        $detected = strtolower((string) $this->note->language_detected);

        if ($detected !== '' && in_array($detected, $rtl, true)) {
            return true;
        }

        // Fall back to the text itself - detection can be null on older notes.
        $sample = $this->note->transcript?->full_text ?? '';

        return (bool) preg_match(
            '/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u',
            mb_substr($sample, 0, 400),
        );
    }

    /**
     * A plain text rendering of the digest for the copy button - something that
     * survives being pasted into a chat app. Deliberately not the transcript:
     * that has its own copy button, because pasting nine minutes of speech into
     * a group chat is rarely what anyone wants.
     */
    public function plainTextDigest(): string
    {
        $digest = $this->note->digest;

        if ($digest === null) {
            return '';
        }

        $lines = [];

        foreach ($digest->summary as $point) {
            $lines[] = '- '.$point;
        }

        if (count($digest->questions)) {
            $lines[] = '';
            $lines[] = 'Questions for you:';

            foreach ($digest->questions as $question) {
                $lines[] = '- '.$question;
            }
        }

        $details = [];

        foreach (($digest->entities ?? []) as $values) {
            if (is_array($values)) {
                $details = array_merge($details, $values);
            }
        }

        if ($details !== []) {
            $lines[] = '';
            $lines[] = 'Key details: '.implode(', ', $details);
        }

        if (count($digest->action_items)) {
            $lines[] = '';
            $lines[] = 'To do:';

            foreach ($digest->action_items as $item) {
                $lines[] = '- '.$item;
            }
        }

        if (count($digest->notes ?? [])) {
            $lines[] = '';
            $lines[] = '(Some parts of the recording were unclear.)';
        }

        return implode("\n", $lines);
    }

    public function render()
    {
        return view('livewire.view-note')->title($this->note->original_filename.' · Sho el Zbde');
    }
}
