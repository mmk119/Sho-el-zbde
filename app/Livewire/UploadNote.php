<?php

namespace App\Livewire;

use App\Actions\CreateVoiceNote;
use App\Support\UploadRules;
use App\Support\UploadThrottle;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class UploadNote extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    #[Validate('nullable|string|max:16')]
    public string $language_hint = '';

    #[Validate('nullable|string|max:500')]
    public string $prompt_hint = '';

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.UploadRules::maxKilobytes(),
                'mimetypes:'.implode(',', UploadRules::acceptedMimes()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Pick a voice note first.',
            'file.max' => 'That file is larger than the '.UploadRules::maxMegabytes().'MB limit.',
            'file.mimetypes' => 'That does not look like an audio file we can read.',
        ];
    }

    /** Validate as soon as something is dropped, rather than after a long upload. */
    public function updatedFile(): void
    {
        $this->validateOnly('file');
    }

    public function save(CreateVoiceNote $create)
    {
        $this->validate();

        if (UploadThrottle::exceeded(request()->ip(), auth()->id())) {
            $this->addError('file', UploadThrottle::message(request()->ip(), auth()->id()));

            return null;
        }

        /** @var UploadedFile $upload */
        $upload = $this->file;

        [$seconds, $error] = UploadRules::inspect($upload->getRealPath());

        if ($error !== null) {
            $this->addError('file', $error);

            return null;
        }

        $note = $create(
            file: $upload,
            durationSeconds: $seconds,
            languageHint: $this->language_hint,
            promptHint: $this->prompt_hint,
            userId: auth()->id(),
            ip: request()->ip(),
        );

        return $this->redirect(route('notes.show', $note->public_token), navigate: true);
    }

    public function render()
    {
        return view('livewire.upload-note', [
            'languages' => self::languages(),
        ]);
    }

    /**
     * Auto detect first, deliberately. Arabic dialect is the hard case but it is
     * not a special path - it sits in the list like everything else.
     *
     * @return array<string, string>
     */
    public static function languages(): array
    {
        return [
            '' => 'Detect automatically',
            'ar' => 'Arabic / العربية',
            'en' => 'English',
            'fr' => 'French / Français',
            'es' => 'Spanish / Español',
            'de' => 'German / Deutsch',
            'tr' => 'Turkish / Türkçe',
            'hi' => 'Hindi / हिन्दी',
            'ur' => 'Urdu / اردو',
            'fa' => 'Persian / فارسی',
            'ru' => 'Russian / Русский',
            'pt' => 'Portuguese / Português',
            'it' => 'Italian / Italiano',
            'zh' => 'Chinese / 中文',
            'ja' => 'Japanese / 日本語',
        ];
    }
}
