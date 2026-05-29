<?php

namespace App\Livewire\Documents;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\DocumentProcessingAttempt;
use App\Models\DocumentTextExtraction;
use App\Models\DocumentClassification;
use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\ProductFromCandidateCreator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DocumentShow extends Component
{
    use AuthorizesRequests;

    /**
     * Documento mostrato nella pagina dettaglio.
     */
    public Document $document;

    /**
     * Ultimo tentativo di processing del documento.
     */
    public ?DocumentProcessingAttempt $latestProcessingAttempt = null;

    /**
     * Ultimo tentativo di estrazione testo del documento.
     */
    public ?DocumentTextExtraction $latestTextExtraction = null;

    /**
     * Classificazione selezionata del documento.
     */
    public ?DocumentClassification $selectedClassification = null;

    /**
     * Inizializza il componente con route model binding.
     */
    public function mount(Document $document): void
    {
        $this->authorize('view', $document);

        $this->document = $document->load([
            'documentType',
            'merchant',
            'currency',
            'uploadedBy',
            'selectedClassification.documentType',
            'lines.documentLineType',
            'productIdentificationCandidates.documentLine',
            'productIdentificationCandidates.product',
        ]);

        $this->latestProcessingAttempt = $this->document
            ->processingAttempts()
            ->latest()
            ->first();

        $this->latestTextExtraction = $this->document
            ->latestTextExtraction()
            ->first();

        $this->selectedClassification = $this->document
            ->selectedClassification()
            ->with('documentType')
            ->first();
    }

    /**
     * Etichetta leggibile dello stato documento.
     */
    public function getStatusLabelProperty(): string
    {
        return match ($this->document->status) {
            'uploaded' => 'Caricato',
            'processing' => 'In elaborazione',
            'text_extracted' => 'Testo estratto',
            'classified' => 'Classificato',
            'parsed' => 'Analizzato',
            'needs_review' => 'Da revisionare',
            'low_confidence' => 'Bassa affidabilità',
            'linked_to_product' => 'Collegato a prodotto',
            'unsupported' => 'Non supportato',
            'failed' => 'Fallito',
            default => ucfirst(str_replace('_', ' ', $this->document->status ?? 'uploaded')),
        };
    }

    /**
     * Classi CSS del badge stato.
     */
    public function getStatusBadgeClassesProperty(): string
    {
        return match ($this->document->status) {
            'uploaded' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            'processing' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
            'text_extracted' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
            'classified' => 'bg-purple-50 text-purple-700 ring-purple-600/20',
            'parsed' => 'bg-cyan-50 text-cyan-700 ring-cyan-600/20',
            'needs_review' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
            'low_confidence' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            'linked_to_product' => 'bg-green-50 text-green-700 ring-green-600/20',
            'unsupported' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
            'failed' => 'bg-red-50 text-red-700 ring-red-600/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Formatta una dimensione in byte in formato leggibile.
     */
    public function formatBytes(?int $bytes): string
    {
        if (! $bytes) {
            return '—';
        }

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 2, ',', '.') . ' KB';
        }

        return number_format($bytes / 1024 / 1024, 2, ',', '.') . ' MB';
    }

    /**
     * Etichetta leggibile dello stato di estrazione testo.
     */
    public function getTextExtractionStatusLabelProperty(): string
    {
        return match ($this->document->text_extraction_status) {
            null => 'Non avviata',
            'pending' => 'In attesa',
            'requires_ocr' => 'Richiede OCR',
            'running' => 'In corso',
            'completed' => 'Completata',
            'failed' => 'Fallita',
            default => ucfirst(str_replace('_', ' ', $this->document->text_extraction_status)),
        };
    }

    /**
     * Classi CSS del badge estrazione testo.
     */
    public function getTextExtractionStatusBadgeClassesProperty(): string
    {
        return match ($this->document->text_extraction_status) {
            null => 'bg-gray-100 text-gray-700 ring-gray-500/20',
            'pending' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
            'running' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            'requires_ocr' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
            'completed' => 'bg-green-50 text-green-700 ring-green-600/20',
            'failed' => 'bg-red-50 text-red-700 ring-red-600/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Etichetta leggibile dello stato dell'ultimo tentativo di processing.
     */
    public function getLatestAttemptStatusLabelProperty(): string
    {
        return match ($this->latestProcessingAttempt?->status) {
            null => 'Nessun tentativo',
            'running' => 'In corso',
            'completed' => 'Completato',
            'failed' => 'Fallito',
            default => ucfirst(str_replace('_', ' ', $this->latestProcessingAttempt->status)),
        };
    }

    /**
     * Classi CSS del badge dell'ultimo tentativo di processing.
     */
    public function getLatestAttemptStatusBadgeClassesProperty(): string
    {
        return match ($this->latestProcessingAttempt?->status) {
            null => 'bg-gray-100 text-gray-700 ring-gray-500/20',
            'running' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            'completed' => 'bg-green-50 text-green-700 ring-green-600/20',
            'failed' => 'bg-red-50 text-red-700 ring-red-600/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Etichetta leggibile dello step tecnico dell'ultimo tentativo.
     */
    public function getLatestAttemptStepLabelProperty(): string
    {
        return match ($this->latestProcessingAttempt?->step) {
            null => 'Nessuno step eseguito',
            'bootstrap' => 'Controllo iniziale del file',
            'text_extraction' => 'Estrazione del testo',
            'classification' => 'Classificazione del documento',
            'parsing' => 'Analisi dei dati del documento',
            'merchant_parsing' => 'Riconoscimento venditore',
            'line_parsing' => 'Estrazione righe prodotto',
            'product_candidate_generation' => 'Generazione candidati prodotto',
            'product_draft' => 'Creazione bozza prodotto',
            'scoring' => 'Calcolo affidabilità',
            default => ucfirst(str_replace('_', ' ', $this->latestProcessingAttempt->step)),
        };
    }

    /**
     * Stabilisce se l'utente può avviare o riprovare il processing.
     */
    public function getCanStartProcessingProperty(): bool
    {
        if ($this->document->text_extraction_status === null) {
            return true;
        }

        if ($this->document->text_extraction_status === 'failed') {
            return true;
        }

        return $this->latestProcessingAttempt?->status === 'failed';
    }

    /**
     * Avvia manualmente il processing del documento.
     */
    public function startProcessing(): void
    {
        $this->authorize('update', $this->document);

        $this->document->refresh();

        $this->latestProcessingAttempt = $this->document
            ->processingAttempts()
            ->latest()
            ->first();

        if (! $this->canStartProcessing) {
            session()->flash('processing_warning', 'Il processing è già in attesa o è già stato eseguito.');

            return;
        }

        $this->document->update([
            'text_extraction_status' => 'pending',
        ]);

        ProcessDocumentJob::dispatch($this->document->id);

        $this->document->refresh();

        $this->latestProcessingAttempt = $this->document
            ->processingAttempts()
            ->latest()
            ->first();

        session()->flash('processing_success', 'Processing avviato correttamente.');
    }

    /**
     * Conferma un candidato prodotto e crea la scheda prodotto definitiva.
     */
    public function confirmProductCandidate(
        int $candidateId,
        ProductFromCandidateCreator $productFromCandidateCreator
    ): void {
        $this->authorize('update', $this->document);

        $this->document->refresh();

        /*
        |--------------------------------------------------------------------------
        | MVP: un prodotto principale per documento
        |--------------------------------------------------------------------------
        |
        | Per ora impediamo di creare più prodotti dallo stesso documento.
        | Quando gestiremo documenti multi-prodotto, cambieremo questa regola.
        |
        */
        if ($this->document->status === 'linked_to_product') {
            session()->flash('product_warning', 'Questo documento è già collegato a un prodotto.');

            return;
        }

        $candidate = ProductIdentificationCandidate::query()
            ->where('document_id', $this->document->id)
            ->whereKey($candidateId)
            ->firstOrFail();

        if ($candidate->product_id) {
            session()->flash('product_warning', 'Questo candidato è già stato trasformato in prodotto.');

            return;
        }

        $product = $productFromCandidateCreator->create(
            candidate: $candidate,
            userId: (int) Auth::id(),
        );

        $this->document = $this->document->fresh([
            'documentType',
            'merchant',
            'currency',
            'uploadedBy',
            'selectedClassification.documentType',
            'lines.documentLineType',
            'productIdentificationCandidates.documentLine',
            'productIdentificationCandidates.product',
        ]);

        $this->latestProcessingAttempt = $this->document
            ->processingAttempts()
            ->latest()
            ->first();

        $this->latestTextExtraction = $this->document
            ->latestTextExtraction()
            ->first();

        $this->selectedClassification = $this->document
            ->selectedClassification()
            ->with('documentType')
            ->first();

        session()->flash('product_success', 'Prodotto creato correttamente: ' . $product->name);
    }

    /**
     * Etichetta leggibile dello stato dell'ultima estrazione testo.
     */
    public function getLatestTextExtractionStatusLabelProperty(): string
    {
        return match ($this->latestTextExtraction?->status) {
            null => 'Nessuna estrazione',
            'running' => 'In corso',
            'completed' => 'Completata',
            'requires_ocr' => 'Richiede OCR',
            'failed' => 'Fallita',
            default => ucfirst(str_replace('_', ' ', $this->latestTextExtraction->status)),
        };
    }

    /**
     * Classi CSS del badge dell'ultima estrazione testo.
     */
    public function getLatestTextExtractionStatusBadgeClassesProperty(): string
    {
        return match ($this->latestTextExtraction?->status) {
            null => 'bg-gray-100 text-gray-700 ring-gray-500/20',
            'running' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            'completed' => 'bg-green-50 text-green-700 ring-green-600/20',
            'requires_ocr' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
            'failed' => 'bg-red-50 text-red-700 ring-red-600/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Etichetta leggibile del motore di estrazione usato.
     */
    public function getLatestTextExtractionEngineLabelProperty(): string
    {
        return match ($this->latestTextExtraction?->engine) {
            null => '—',
            'smalot_pdfparser' => 'Smalot PDF Parser',
            'image_ocr_pending' => 'OCR immagine non ancora eseguito',
            'media_lookup' => 'Controllo media',
            'storage_lookup' => 'Controllo storage',
            'unsupported_mime' => 'Formato non supportato',
            default => str_replace('_', ' ', $this->latestTextExtraction->engine),
        };
    }

    /**
     * Anteprima breve del testo estratto.
     */
    public function getLatestRawTextPreviewProperty(): ?string
    {
        if (! $this->latestTextExtraction?->raw_text) {
            return null;
        }

        return mb_substr($this->latestTextExtraction->raw_text, 0, 1500);
    }

    /**
     * Etichetta leggibile del tipo documento classificato.
     */
    public function getSelectedClassificationTypeLabelProperty(): string
    {
        return $this->selectedClassification?->documentType?->name ?? 'Non classificato';
    }

    /**
     * Etichetta leggibile del motore di classificazione.
     */
    public function getSelectedClassificationEngineLabelProperty(): string
    {
        return match ($this->selectedClassification?->classifier) {
            null => '—',
            'rule_based_v1' => 'Regole automatiche v1',
            default => str_replace('_', ' ', $this->selectedClassification->classifier),
        };
    }

    /**
     * Badge della confidenza della classificazione.
     */
    public function getSelectedClassificationConfidenceBadgeClassesProperty(): string
    {
        $score = $this->selectedClassification?->confidence_score;

        if ($score === null) {
            return 'bg-gray-100 text-gray-700 ring-gray-500/20';
        }

        if ($score >= 80) {
            return 'bg-green-50 text-green-700 ring-green-600/20';
        }

        if ($score >= 50) {
            return 'bg-yellow-50 text-yellow-800 ring-yellow-600/20';
        }

        return 'bg-red-50 text-red-700 ring-red-600/20';
    }

    /**
     * Mostra la pagina dettaglio documento.
     */
    public function render(): View
    {
        return view('livewire.documents.document-show')
            ->layout('layouts.app');
    }
}