<?php

namespace App\Livewire\Documents;

use App\Actions\Documents\StoreUploadedDocumentAction;
use App\Exceptions\Monetization\PlanLimitExceededException;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithFileUploads;

class DocumentUpload extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public $file = null;

    public bool $fileValidated = false;

    public bool $previewOpen = false;

    protected function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:10240',
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'file.required' => 'Seleziona un file da caricare.',
            'file.file' => 'Il contenuto selezionato non è un file valido.',
            'file.mimes' => 'Sono ammessi solo file PDF, JPG, JPEG, PNG o WEBP.',
            'file.max' => 'Il file non può superare i 10 MB.',
        ];
    }

    public function updatedFile(): void
    {
        $this->fileValidated = false;
        $this->previewOpen = false;
        $this->validateOnly('file');
    }

    public function store()
    {
        $this->authorize('create', Document::class);
        $this->validate();

        try {
            app(StoreUploadedDocumentAction::class)
                ->handle($this->file);
        } catch (PlanLimitExceededException $exception) {
            $this->addError('file', $exception->getMessage());

            return null;
        }

        session()->flash(
            'success',
            'Documento caricato correttamente.'
        );

        return $this->redirectRoute('documents.index');
    }

    public function openPreview(): void
    {
        if (! $this->file) {
            return;
        }

        if (! str_starts_with(
            $this->file->getMimeType(),
            'image/'
        )) {
            return;
        }

        $this->previewOpen = true;
    }

    public function closePreview(): void
    {
        $this->previewOpen = false;
    }

    public function render(): View
    {
        $this->authorize('create', Document::class);

        return view('livewire.documents.document-upload')
            ->layout('layouts.app');
    }
}
