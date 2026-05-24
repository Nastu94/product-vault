<?php

namespace App\Livewire\Documents;

use App\Actions\Documents\StoreUploadedDocumentAction;
use App\Models\Document;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithFileUploads;

class DocumentUpload extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    /**
     * File caricato temporaneamente da Livewire.
     *
     * In questo step il file viene solo validato.
     * Il salvataggio su Document + Spatie Media Library arriverà nello step successivo.
     */
    public $file = null;

    /**
     * Indica se il file selezionato ha superato la validazione.
     */
    public bool $fileValidated = false;

    /**
     * Gestisce l'apertura della preview immagine a schermo intero.
     */
    public bool $previewOpen = false;

    /**
     * Regole di validazione MVP.
     *
     * Accettiamo solo file utili al ciclo di vita prodotto:
     * PDF, immagini prodotto, foto barcode, foto seriale, scontrini e fatture.
     */
    protected function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:10240', // 10 MB, espresso in KB
            ],
        ];
    }

    /**
     * Messaggi leggibili per l'utente.
     */
    protected function messages(): array
    {
        return [
            'file.required' => 'Seleziona un file da caricare.',
            'file.file' => 'Il contenuto selezionato non è un file valido.',
            'file.mimes' => 'Sono ammessi solo file PDF, JPG, JPEG, PNG o WEBP.',
            'file.max' => 'Il file non può superare i 10 MB.',
        ];
    }


    /**
     * Quando l'utente seleziona un file, azzeriamo lo stato precedente.
     */
    public function updatedFile(): void
    {
        $this->fileValidated = false;
        $this->previewOpen = false;

        $this->validateOnly('file');
    }

    /**
     * Salva il documento e il file originale.
     */
    public function store()
    {
        $this->authorize('create', Document::class);

        $this->validate();

        app(StoreUploadedDocumentAction::class)->handle($this->file);

        session()->flash('success', 'Documento caricato correttamente.');

        return $this->redirectRoute('documents.index');
    }

    /**
     * Apre la preview fullscreen del file immagine selezionato.
     */
    public function openPreview(): void
    {
        if (! $this->file) {
            return;
        }

        if (! str_starts_with($this->file->getMimeType(), 'image/')) {
            return;
        }

        $this->previewOpen = true;
    }

    /**
     * Chiude la preview fullscreen.
     */
    public function closePreview(): void
    {
        $this->previewOpen = false;
    }

    /**
     * Mostra la schermata di upload documento.
     */
    public function render(): View
    {
        $this->authorize('create', Document::class);

        return view('livewire.documents.document-upload')
            ->layout('layouts.app');
    }
}