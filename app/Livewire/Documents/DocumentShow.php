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
use App\Services\Documents\AssistedReview\AssistedReviewConfirmationBlockedException;
use App\Services\Documents\AssistedReview\AssistedReviewConfirmationGuard;
use App\Services\Documents\ProductUnderstanding\ProductUnderstandingFeedbackRecorder;
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
     * Campi modificabili per i candidati prodotto.
     */
    public array $candidateReviewForms = [];

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
        $this->fillCandidateReviewForms();
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
     * Restituisce lo stato di confermabilità del candidato.
     *
     * La view usa lo stesso guardrail applicato dal creator, così il
     * messaggio mostrato all'utente resta coerente con il backend.
     *
     * @return array{
     *     allowed: bool,
     *     reason: string,
     *     unresolved_fields: array<int, string>,
     *     message: string|null
     * }
     */
    public function candidateConfirmationState(
        ProductIdentificationCandidate $candidate
    ): array {
        return app(
            AssistedReviewConfirmationGuard::class
        )->evaluate($candidate);
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

        $candidate = ProductIdentificationCandidate::query()
            ->where('document_id', $this->document->id)
            ->whereKey($candidateId)
            ->firstOrFail();

        if ($candidate->product_id) {
            session()->flash('product_warning', 'Questo candidato è già stato trasformato in prodotto.');

            return;
        }

        try {
            $product = $productFromCandidateCreator->create(
                candidate: $candidate,
                userId: (int) Auth::id(),
            );
        } catch (
            AssistedReviewConfirmationBlockedException $exception
        ) {
            session()->flash(
                'product_warning',
                $exception->getMessage()
            );

            return;
        }

        $this->refreshDocumentState();

        session()->flash('product_success', 'Prodotto creato correttamente: ' . $product->name);
        $this->closeActiveDrawer();
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
        $this->fillCandidateReviewForms();
    }

    /**
     * Chiude il drawer attualmente aperto nella UI.
     */
    private function closeActiveDrawer(): void
    {
        $this->dispatch('close-drawer');
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

        $line = DocumentLine::query()
            ->where('document_id', $this->document->id)
            ->whereKey($lineId)
            ->firstOrFail();

        $hasLinkedProduct = $line->productIdentificationCandidates()
            ->whereNotNull('product_id')
            ->exists();

        if ($hasLinkedProduct) {
            session()->flash('line_warning', 'Questa riga ha già generato un prodotto e non può essere modificata.');

            return;
        }

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
        $this->fillDocumentReviewForm();
        $this->fillLineReviewForms();
        $this->fillCandidateReviewForms();

        session()->flash('line_success', 'Riga documento aggiornata. Rigenera i candidati per applicare la modifica.');
        $this->closeActiveDrawer();
    }

    /**
     * Elimina una riga documento dalla revisione.
     */
    public function deleteDocumentLine(int $lineId): void
    {
        $this->authorize('update', $this->document);

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
        $this->fillDocumentReviewForm();
        $this->fillLineReviewForms();
        $this->fillCandidateReviewForms();

        session()->flash('line_success', 'Riga eliminata. Rigenera i candidati per aggiornare la revisione.');
        $this->closeActiveDrawer();
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
        $this->fillDocumentReviewForm();
        $this->fillLineReviewForms();
        $this->fillCandidateReviewForms();

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
     * Popola il form di revisione candidati prodotto.
     */
    private function fillCandidateReviewForms(): void
    {
        $this->candidateReviewForms = [];

        foreach ($this->document->productIdentificationCandidates as $candidate) {
            $this->candidateReviewForms[$candidate->id] = [
                'name' => (string) ($candidate->name ?? ''),
                'model' => (string) ($candidate->model ?? ''),
                'serial_number' => (string) ($candidate->serial_number ?? ''),
                'ean_code' => (string) ($candidate->ean_code ?? ''),
                'price' => $candidate->price !== null
                    ? number_format((float) $candidate->price, 2, '.', '')
                    : '',
            ];
        }
    }

    /**
     * Applica al candidato il nome canonico suggerito dalla conoscenza globale.
     *
     * Non conferma il prodotto e non crea Product.
     * Corregge solo il nome candidato usando un suggerimento globale già tracciato
     * nei metadata del candidato.
     */
    public function applyGlobalCanonicalNameToCandidate(int $candidateId): void
    {
        $this->authorize('update', $this->document);

        $candidate = ProductIdentificationCandidate::query()
            ->where('document_id', $this->document->id)
            ->whereKey($candidateId)
            ->firstOrFail();

        if ($candidate->product_id) {
            session()->flash('candidate_warning', 'Questo candidato ha già generato un prodotto e non può essere modificato.');

            return;
        }

        $metadata = $candidate->metadata ?? [];
        $globalFact = $metadata['product_understanding_global_fact'] ?? [];

        if (($globalFact['matched'] ?? false) !== true) {
            session()->flash('candidate_warning', 'Nessun suggerimento globale disponibile per questo candidato.');

            return;
        }

        $canonicalName = trim((string) ($globalFact['canonical_name'] ?? ''));

        if ($canonicalName === '') {
            session()->flash('candidate_warning', 'Il suggerimento globale non contiene un nome valido.');

            return;
        }

        if ($canonicalName === (string) $candidate->name) {
            session()->flash('candidate_warning', 'Il candidato usa già il nome globale suggerito.');

            return;
        }

        $metadata['manual_review'] = [
            'reviewed' => true,
            'reviewed_at' => now()->toISOString(),
            'reviewed_by_user_id' => auth()->id(),
        ];

        $metadata['global_canonical_name_applied'] = [
            'applied' => true,
            'previous_name' => $candidate->name,
            'canonical_name' => $canonicalName,
            'source' => 'product_understanding_global_fact',
            'applied_at' => now()->toISOString(),
            'applied_by_user_id' => auth()->id(),
        ];

        $candidate->update([
            'name' => $canonicalName,
            'confidence_score' => max((int) ($candidate->confidence_score ?? 0), 95),
            'metadata' => $metadata,
        ]);

        $this->refreshDocumentState();

        session()->flash('candidate_success', 'Nome candidato aggiornato usando il suggerimento globale.');

        $this->closeActiveDrawer();
    }

    /**
     * Salva le correzioni manuali di un candidato prodotto.
     */
    public function saveProductCandidateReviewData(int $candidateId): void
    {
        $this->authorize('update', $this->document);

        $candidate = ProductIdentificationCandidate::query()
            ->where('document_id', $this->document->id)
            ->whereKey($candidateId)
            ->firstOrFail();

        if ($candidate->product_id) {
            session()->flash('candidate_warning', 'Questo candidato ha già generato un prodotto e non può essere modificato.');

            return;
        }

        $form = $this->candidateReviewForms[$candidateId] ?? [];

        $name = trim((string) ($form['name'] ?? ''));

        if ($name === '') {
            $this->addError("candidateReviewForms.{$candidateId}.name", 'Il nome prodotto è obbligatorio.');

            return;
        }

        $price = $this->normalizeDecimalInput($form['price'] ?? null);

        if (($form['price'] ?? '') !== '' && $price === null) {
            $this->addError("candidateReviewForms.{$candidateId}.price", 'Il prezzo candidato non è valido.');

            return;
        }

        $metadata = $candidate->metadata ?? [];

        $metadata['manual_review'] = [
            'reviewed' => true,
            'reviewed_at' => now()->toISOString(),
            'reviewed_by_user_id' => auth()->id(),
        ];

        $candidate->update([
            'name' => $name,
            'model' => trim((string) ($form['model'] ?? '')) ?: null,
            'serial_number' => trim((string) ($form['serial_number'] ?? '')) ?: null,
            'ean_code' => trim((string) ($form['ean_code'] ?? '')) ?: null,
            'price' => $price,
            'confidence_score' => 100,
            'metadata' => $metadata,
        ]);

        $this->refreshDocumentState();

        session()->flash('candidate_success', 'Candidato prodotto aggiornato correttamente.');
        $this->closeActiveDrawer();
    }

    /**
     * Aggiorna lo stato documento dopo conferma/esclusione candidati.
     */
    private function updateDocumentStatusAfterCandidateReview(): void
    {
        $pendingCandidatesCount = $this->document
            ->productIdentificationCandidates()
            ->where('review_status', 'pending')
            ->whereNull('product_id')
            ->count();

        $confirmedCandidatesCount = $this->document
            ->productIdentificationCandidates()
            ->where('review_status', 'confirmed')
            ->whereNotNull('product_id')
            ->count();

        $bestConfirmedCandidateScore = $this->document
            ->productIdentificationCandidates()
            ->where('review_status', 'confirmed')
            ->whereNotNull('product_id')
            ->max('confidence_score');

        $this->document->update([
            'status' => $pendingCandidatesCount > 0
                ? 'needs_review'
                : ($confirmedCandidatesCount > 0 ? 'linked_to_product' : 'parsed'),
            'product_reliability_score' => $bestConfirmedCandidateScore !== null
                ? (int) $bestConfirmedCandidateScore
                : null,
        ]);
    }

    /**
     * Esclude un candidato prodotto senza eliminarlo.
     *
     * Manteniamo il candidato per tracciabilità: il sistema lo aveva proposto,
     * ma l'utente ha deciso di non trasformarlo in prodotto.
     */
    public function ignoreProductCandidate(int $candidateId): void
    {
        $this->authorize('update', $this->document);

        $candidate = ProductIdentificationCandidate::query()
            ->where('document_id', $this->document->id)
            ->whereKey($candidateId)
            ->firstOrFail();

        if ($candidate->product_id) {
            session()->flash('candidate_warning', 'Questo candidato ha già generato un prodotto e non può essere escluso.');

            return;
        }

        if ($candidate->review_status === 'ignored') {
            session()->flash('candidate_warning', 'Questo candidato è già stato escluso.');

            return;
        }

        $candidate->update([
            'review_status' => 'ignored',
            'ignored_reason' => 'not_to_register',
            'ignored_note' => null,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
            'is_selected' => false,
        ]);

        $candidate->refresh();

        app(ProductUnderstandingFeedbackRecorder::class)->recordIgnoredCandidate(
            candidate: $candidate,
            userId: (int) Auth::id(),
            reason: 'not_to_register',
            note: null,
        );

        $this->updateDocumentStatusAfterCandidateReview();
        $this->refreshDocumentState();

        session()->flash('candidate_success', 'Candidato prodotto escluso dalla revisione.');

        $this->closeActiveDrawer();
    }

    /**
     * Manteniamo compatibilità con eventuali vecchi pulsanti.
     *
     * Da ora l'azione corretta è escludere il candidato, non eliminarlo.
     */
    public function deleteProductCandidate(int $candidateId): void
    {
        $this->ignoreProductCandidate($candidateId);
    }

    /**
     * Diagnostica sintetica dell'estrazione righe documento.
     *
     * Serve per capire quale parser ha prodotto le righe, con quali strategie
     * e se sono presenti warning o linee di supporto utili alla revisione.
     */
    public function getLineExtractionDiagnosticsProperty(): array
    {
        $lines = $this->document->lines;

        $parserCounts = [];
        $modeCounts = [];
        $strategyCounts = [];
        $scores = [];
        $warnings = [];
        $supportingLinesCount = 0;
        $linesWithSupportingLines = 0;

        foreach ($lines as $line) {
            $metadata = $line->metadata ?? [];

            $parser = $metadata['parser'] ?? null;
            $mode = $metadata['mode'] ?? null;
            $strategy = $metadata['extraction_strategy'] ?? null;
            $score = $metadata['extraction_score'] ?? null;

            if ($parser) {
                $parserCounts[$parser] = ($parserCounts[$parser] ?? 0) + 1;
            }

            if ($mode) {
                $modeCounts[$mode] = ($modeCounts[$mode] ?? 0) + 1;
            }

            if ($strategy) {
                $strategyCounts[$strategy] = ($strategyCounts[$strategy] ?? 0) + 1;
            }

            if ($score !== null && is_numeric($score)) {
                $scores[] = (int) $score;
            }

            $lineWarnings = array_merge(
                $this->normalizeMetadataList($metadata['extraction_warnings'] ?? []),
                $this->normalizeMetadataList($metadata['row_warnings'] ?? [])
            );

            foreach ($lineWarnings as $warning) {
                $warnings[] = $warning;
            }

            $supportingLines = $this->normalizeMetadataList($metadata['supporting_lines'] ?? []);

            if ($supportingLines !== []) {
                $linesWithSupportingLines++;
                $supportingLinesCount += count($supportingLines);
            }
        }

        $latestLineParsingAttempt = $this->document
            ->processingAttempts()
            ->where('step', 'line_parsing')
            ->latest('id')
            ->first();

        $latestCandidateGenerationAttempt = $this->document
            ->processingAttempts()
            ->where('step', 'product_candidate_generation')
            ->latest('id')
            ->first();

        return [
            'lines_count' => $lines->count(),
            'candidates_count' => $this->document->productIdentificationCandidates->count(),
            'parser_counts' => $parserCounts,
            'mode_counts' => $modeCounts,
            'strategy_counts' => $strategyCounts,
            'average_extraction_score' => $scores !== []
                ? round(array_sum($scores) / count($scores))
                : null,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'supporting_lines_count' => $supportingLinesCount,
            'lines_with_supporting_lines' => $linesWithSupportingLines,
            'line_parsing_lines_created' => $latestLineParsingAttempt?->metadata['lines_created'] ?? null,
            'candidate_generation_candidates_created' => $latestCandidateGenerationAttempt?->metadata['candidates_created'] ?? null,
            'pending_candidates_count' => $this->document->productIdentificationCandidates
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->count(),
            'confirmed_candidates_count' => $this->document->productIdentificationCandidates
                ->where('review_status', 'confirmed')
                ->whereNotNull('product_id')
                ->count(),
            'ignored_candidates_count' => $this->document->productIdentificationCandidates
                ->where('review_status', 'ignored')
                ->count(),
        ];
    }

    /**
     * Normalizza valori metadata che possono arrivare come stringa, array o null.
     */
    private function normalizeMetadataList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter(
                $value,
                fn ($item): bool => $item !== null && trim((string) $item) !== ''
            ));
        }

        return [trim((string) $value)];
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