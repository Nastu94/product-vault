<?php

namespace App\Livewire\Documents;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\DocumentClassification;
use App\Models\DocumentLine;
use App\Models\DocumentProcessingAttempt;
use App\Models\DocumentTextExtraction;
use App\Models\ProductIdentificationCandidate;
use App\Models\Merchant;
use App\Services\Documents\ProductCandidateGenerator;
use App\Services\Documents\ProductFromCandidateCreator;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DocumentShow extends Component
{
    use AuthorizesRequests;

    /**
     * Documento mostrato nella pagina dettaglio.
     */
    public Document $document;

    /**
     * Campi modificabili nella revisione manuale dei dati base.
     */
    public string $editMerchantName = '';

    public ?string $editPurchaseDate = null;

    public ?string $editTotalAmount = null;

    /**
     * Campi modificabili per le righe documento.
     */
    public array $lineReviewForms = [];

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

        $this->fillDocumentReviewForm();
        $this->fillLineReviewForms();
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
     * Popola il form di revisione con i dati correnti del documento.
     */
    private function fillDocumentReviewForm(): void
    {
        $this->editMerchantName = (string) ($this->document->merchant?->name ?? '');

        $this->editPurchaseDate = $this->document->purchase_date
            ? $this->document->purchase_date->format('Y-m-d')
            : null;

        $this->editTotalAmount = $this->document->total_amount !== null
            ? number_format((float) $this->document->total_amount, 2, '.', '')
            : null;
    }

    /**
     * Salva le correzioni manuali dei dati base documento.
     */
    public function saveDocumentReviewData(): void
    {
        $this->authorize('update', $this->document);

        $this->resetErrorBag();

        $this->validate([
            'editMerchantName' => ['nullable', 'string', 'max:160'],
            'editPurchaseDate' => ['nullable', 'date'],
        ], [
            'editMerchantName.max' => 'Il nome venditore non può superare 160 caratteri.',
            'editPurchaseDate.date' => 'La data inserita non è valida.',
        ]);

        $totalAmount = $this->normalizeDecimalInput($this->editTotalAmount);

        if ($this->editTotalAmount !== null && trim($this->editTotalAmount) !== '' && $totalAmount === null) {
            $this->addError('editTotalAmount', 'Il totale inserito non è valido.');

            return;
        }

        $merchant = $this->findOrCreateManualMerchant($this->editMerchantName);

        $this->document->update([
            'merchant_id' => $merchant?->id,
            'purchase_date' => $this->editPurchaseDate ?: null,
            'total_amount' => $totalAmount,
        ]);

        $this->refreshDocumentState();
        $this->fillDocumentReviewForm();

        session()->flash('review_success', 'Dati documento aggiornati correttamente.');
    }

    /**
     * Ripristina il form ai valori salvati sul documento.
     */
    public function resetDocumentReviewForm(): void
    {
        $this->document->refresh();

        $this->document->load([
            'documentType',
            'merchant',
            'currency',
        ]);

        $this->fillDocumentReviewForm();

        session()->flash('review_warning', 'Modifiche non salvate annullate.');
    }

    /**
     * Trova o crea un merchant a partire dal nome corretto manualmente.
     */
    private function findOrCreateManualMerchant(?string $merchantName): ?Merchant
    {
        $merchantName = trim((string) $merchantName);

        if ($merchantName === '') {
            return null;
        }

        $normalizedName = $this->normalizeMerchantName($merchantName);

        $merchant = Merchant::query()
            ->where('team_id', $this->document->team_id)
            ->where('normalized_name', $normalizedName)
            ->first();

        if ($merchant) {
            return $merchant;
        }

        return Merchant::query()->create([
            'team_id' => $this->document->team_id,
            'name' => $merchantName,
            'normalized_name' => $normalizedName,
            'is_verified' => false,
            'is_active' => true,
        ]);
    }

    /**
     * Normalizza un nome merchant per evitare duplicati banali.
     */
    private function normalizeMerchantName(string $name): string
    {
        $name = Str::ascii($name);
        $name = mb_strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/i', ' ', $name) ?: $name;
        $name = trim(preg_replace('/\s+/', ' ', $name) ?: $name);

        return $name;
    }

    /**
     * Normalizza importi inseriti manualmente.
     */
    private function normalizeDecimalInput(?string $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',')) {
            $value = str_replace(['.', ' '], '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(' ', '', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * Ricarica documento e relazioni usate dalla pagina.
     */
    private function refreshDocumentState(): void
    {
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

        $this->fillDocumentReviewForm();
        $this->fillLineReviewForms();
    }

    /**
     * Popola il form di revisione righe con i dati correnti.
     */
    private function fillLineReviewForms(): void
    {
        $this->lineReviewForms = [];

        foreach ($this->document->lines->sortBy('line_number') as $line) {
            $this->lineReviewForms[$line->id] = [
                'description' => (string) ($line->description ?? ''),
                'quantity' => $line->quantity !== null
                    ? number_format((float) $line->quantity, 3, '.', '')
                    : '',
                'unit_price' => $line->unit_price !== null
                    ? number_format((float) $line->unit_price, 2, '.', '')
                    : '',
                'total_price' => $line->total_price !== null
                    ? number_format((float) $line->total_price, 2, '.', '')
                    : '',
                'product_code_candidate' => (string) ($line->metadata['product_code_candidate'] ?? ''),
                'serial_number_candidate' => (string) ($line->metadata['serial_number_candidate'] ?? ''),
            ];
        }
    }

    /**
     * Salva una riga documento corretta manualmente.
     */
    public function saveDocumentLineReviewData(int $lineId): void
    {
        $this->authorize('update', $this->document);

        if ($this->document->status === 'linked_to_product') {
            session()->flash('line_warning', 'Non puoi modificare le righe dopo aver collegato il documento a un prodotto.');

            return;
        }

        $line = DocumentLine::query()
            ->where('document_id', $this->document->id)
            ->whereKey($lineId)
            ->firstOrFail();

        $form = $this->lineReviewForms[$lineId] ?? [];

        $description = trim((string) ($form['description'] ?? ''));

        if ($description === '') {
            $this->addError("lineReviewForms.{$lineId}.description", 'La descrizione è obbligatoria.');

            return;
        }

        $quantity = $this->normalizeQuantityInput($form['quantity'] ?? null);
        $unitPrice = $this->normalizeDecimalInput($form['unit_price'] ?? null);
        $totalPrice = $this->normalizeDecimalInput($form['total_price'] ?? null);

        if (($form['quantity'] ?? '') !== '' && $quantity === null) {
            $this->addError("lineReviewForms.{$lineId}.quantity", 'La quantità non è valida.');

            return;
        }

        if (($form['unit_price'] ?? '') !== '' && $unitPrice === null) {
            $this->addError("lineReviewForms.{$lineId}.unit_price", 'Il prezzo unitario non è valido.');

            return;
        }

        if (($form['total_price'] ?? '') !== '' && $totalPrice === null) {
            $this->addError("lineReviewForms.{$lineId}.total_price", 'Il totale riga non è valido.');

            return;
        }

        $metadata = $line->metadata ?? [];

        $metadata['product_code_candidate'] = trim((string) ($form['product_code_candidate'] ?? '')) ?: null;
        $metadata['serial_number_candidate'] = trim((string) ($form['serial_number_candidate'] ?? '')) ?: null;
        $metadata['manual_review'] = [
            'reviewed' => true,
            'reviewed_at' => now()->toISOString(),
            'reviewed_by_user_id' => auth()->id(),
        ];

        $line->update([
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'confidence_score' => 100,
            'metadata' => $metadata,
        ]);

        $this->refreshDocumentState();
        $this->fillLineReviewForms();

        session()->flash('line_success', 'Riga documento aggiornata. Rigenera i candidati per applicare la modifica.');
    }

    /**
     * Elimina una riga documento dalla revisione.
     */
    public function deleteDocumentLine(int $lineId): void
    {
        $this->authorize('update', $this->document);

        if ($this->document->status === 'linked_to_product') {
            session()->flash('line_warning', 'Non puoi eliminare righe dopo aver collegato il documento a un prodotto.');

            return;
        }

        $line = DocumentLine::query()
            ->where('document_id', $this->document->id)
            ->whereKey($lineId)
            ->firstOrFail();

        $hasLinkedProduct = $line->productIdentificationCandidates()
            ->whereNotNull('product_id')
            ->exists();

        if ($hasLinkedProduct) {
            session()->flash('line_warning', 'Questa riga ha già generato un prodotto e non può essere eliminata.');

            return;
        }

        $line->productIdentificationCandidates()
            ->whereNull('product_id')
            ->delete();

        $line->delete();

        $this->refreshDocumentState();
        $this->fillLineReviewForms();

        session()->flash('line_success', 'Riga eliminata. Rigenera i candidati per aggiornare la revisione.');
    }

    /**
     * Rigenera i candidati prodotto partendo dalle righe attuali.
     */
    public function regenerateProductCandidates(ProductCandidateGenerator $productCandidateGenerator): void
    {
        $this->authorize('update', $this->document);

        if ($this->document->status === 'linked_to_product') {
            session()->flash('line_warning', 'Non puoi rigenerare candidati dopo aver collegato il documento a un prodotto.');

            return;
        }

        $this->document->refresh();

        $created = $productCandidateGenerator->generate($this->document);

        $this->document->update([
            'status' => $created > 0 ? 'needs_review' : 'parsed',
        ]);

        $this->refreshDocumentState();
        $this->fillLineReviewForms();

        session()->flash(
            'line_success',
            $created > 0
                ? "Candidati rigenerati: {$created}."
                : 'Nessun candidato prodotto generato dalle righe attuali.'
        );
    }

    /**
     * Normalizza quantità inserite manualmente.
     */
    private function normalizeQuantityInput(?string $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 3);
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