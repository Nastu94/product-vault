<?php

namespace App\Livewire\Reviews;

use App\Models\Document;
use App\Models\Category;
use App\Models\ProductIdentificationCandidate;
use App\Models\ProductUnderstandingGlobalFact;
use App\Services\Documents\ProductFromCandidateCreator;
use App\Services\Documents\AssistedReview\AssistedReviewPresenter;
use App\Services\Documents\AssistedReview\AssistedReviewDecisionService;
use App\Services\Documents\AssistedReview\AssistedReviewConfirmationBlockedException;
use App\Services\Documents\DocumentLines\DocumentLineAmountConsistencyChecker;
use App\Services\Documents\ProductUnderstanding\ProductUnderstandingFeedbackRecorder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use InvalidArgumentException;
use RuntimeException;

class ReviewIndex extends Component
{
    use WithPagination;

    /**
     * Numero di candidati mostrati per pagina.
     */
    public int $perPage = 10;

    /**
     * Filtro principale della revisione.
     */
    public string $filter = 'pending';

    /**
     * Mantiene il filtro nella query string.
     */
    protected array $queryString = [
        'filter' => ['except' => 'pending'],
    ];

    /**
     * Candidato selezionato per il drawer di dettaglio conoscenza.
     */
    public ?int $selectedCandidateId = null;

    /**
     * Valori inseriti manualmente nei campi Assisted Review.
     *
     * Struttura:
     * [candidate_id][field_name] = value
     *
     * @var array<int, array<string, mixed>>
     */
    public array $assistedReviewManualForms = [];

    /**
     * Campi per i quali è aperto l'editor manuale.
     *
     * Struttura:
     * [candidate_id][field_name] = true
     *
     * @var array<int, array<string, bool>>
     */
    public array $assistedReviewEditingFields = [];

    /**
     * Reset della pagina e degli editor quando cambia il filtro.
     */
    public function updatedFilter(): void
    {
        $this->selectedCandidateId = null;
        $this->assistedReviewManualForms = [];
        $this->assistedReviewEditingFields = [];

        $this->resetErrorBag();
        $this->resetPage();
    }

    /**
     * Team/workspace corrente.
     */
    private function currentTeamId(): ?int
    {
        $user = Auth::user();

        return $user?->current_team_id ?? $user?->currentTeam?->id;
    }

    /**
     * Query base sui candidati del workspace corrente.
     */
    private function baseCandidateQuery(): Builder
    {
        $teamId = $this->currentTeamId();

        return ProductIdentificationCandidate::query()
            ->whereHas('document', fn (Builder $query) => $query->where('team_id', $teamId));
    }

    /**
     * Query base sui documenti del workspace corrente.
     */
    private function baseDocumentQuery(): Builder
    {
        return Document::query()
            ->where('team_id', $this->currentTeamId());
    }

    /**
     * Applica filtro ai candidati.
     */
    private function applyCandidateFilter(Builder $query): Builder
    {
        return match ($this->filter) {
            'needs_completion' => $query
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->whereRaw("
                    JSON_EXTRACT(
                        metadata,
                        '$.assisted_review.needs_user_completion'
                    ) = true
                "),
            'low_confidence' => $query
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->where(function (Builder $query): void {
                    $query->whereNull('confidence_score')
                        ->orWhere('confidence_score', '<', 80);
                }),

            'python_warnings' => $query
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->whereRaw("JSON_LENGTH(JSON_EXTRACT(metadata, '$.product_understanding_python.warnings')) > 0"),

            'amount_mismatch' => $query
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->where(function (Builder $query): void {
                    $query
                        /*
                        |--------------------------------------------------------------------------
                        | Metadata persistito sui nuovi candidati
                        |--------------------------------------------------------------------------
                        */
                        ->whereRaw("
                            JSON_EXTRACT(metadata, '$.document_line_amount_consistency.checked') = true
                            AND JSON_EXTRACT(metadata, '$.document_line_amount_consistency.is_consistent') = false
                        ")
                        /*
                        |--------------------------------------------------------------------------
                        | Fallback diagnostico per candidati storici senza metadata
                        |--------------------------------------------------------------------------
                        |
                        | Replica il live-check della UI direttamente nella query, così anche i
                        | candidati generati prima della persistenza del metadata vengono trovati.
                        |
                        */
                        ->orWhereRaw("
                            JSON_EXTRACT(metadata, '$.document_line_amount_consistency') IS NULL
                            AND JSON_EXTRACT(metadata, '$.quantity') IS NOT NULL
                            AND JSON_EXTRACT(metadata, '$.unit_price') IS NOT NULL
                            AND JSON_EXTRACT(metadata, '$.total_price') IS NOT NULL
                            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.quantity')) AS DECIMAL(12, 3)) > 0
                            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.unit_price')) AS DECIMAL(12, 2)) > 0
                            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.total_price')) AS DECIMAL(12, 2)) > 0
                            AND ABS(
                                (
                                    CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.quantity')) AS DECIMAL(12, 3))
                                    * CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.unit_price')) AS DECIMAL(12, 2))
                                )
                                - CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.total_price')) AS DECIMAL(12, 2))
                            ) > 0.02
                        ");
                }),

            'global_fact' => $query
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->whereRaw("JSON_EXTRACT(metadata, '$.product_understanding_global_fact.matched') = true"),

            'reviewed' => $query
                ->whereIn('review_status', ['confirmed', 'ignored']),

            default => $query
                ->where('review_status', 'pending')
                ->whereNull('product_id'),
        };
    }

    /**
     * Etichetta leggibile stato candidato.
     */
    public function candidateReviewStatusLabel(ProductIdentificationCandidate $candidate): string
    {
        return match ($candidate->review_status) {
            'pending' => 'Da revisionare',
            'confirmed' => 'Confermato',
            'ignored' => 'Ignorato',
            default => ucfirst((string) $candidate->review_status),
        };
    }

    /**
     * Classi badge stato candidato.
     */
    public function candidateReviewStatusBadgeClasses(ProductIdentificationCandidate $candidate): string
    {
        return match ($candidate->review_status) {
            'pending' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
            'confirmed' => 'bg-green-50 text-green-700 ring-green-600/20',
            'ignored' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Classi del badge associato allo stato Assisted Review.
     *
     * @param  array<string, mixed>  $field
     */
    public function assistedReviewStateBadgeClasses(
        array $field
    ): string {
        return match ($field['state'] ?? 'missing') {
            'present' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
            'suggested' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
            'confirmed' => 'bg-green-50 text-green-700 ring-green-600/20',
            'modified' => 'bg-green-50 text-green-700 ring-green-600/20',
            'declined' => 'bg-gray-100 text-gray-600 ring-gray-400/20',
            default => 'bg-orange-50 text-orange-700 ring-orange-600/20',
        };
    }

    /**
     * Recupera il global fact EAN aggiornato per il candidato.
     *
     * I metadata del candidato sono uno snapshot del momento della generazione.
     * Dopo la conferma prodotto, i global facts possono essere più aggiornati
     * rispetto ai metadata salvati nel candidato.
     */
    public function candidateCurrentGlobalFact(ProductIdentificationCandidate $candidate): ?ProductUnderstandingGlobalFact
    {
        $eanCode = trim((string) $candidate->ean_code);

        if ($eanCode === '') {
            return null;
        }

        return ProductUnderstandingGlobalFact::query()
            ->where('fact_type', 'ean')
            ->where('fact_value', $eanCode)
            ->first();
    }

    /**
     * Etichetta rischio/qualità conoscenza candidato.
     */
    public function candidateKnowledgeLabel(ProductIdentificationCandidate $candidate): string
    {
        $currentGlobalFact = $this->candidateCurrentGlobalFact($candidate);

        if ($candidate->review_status === 'confirmed' || $candidate->product_id !== null) {
            return $currentGlobalFact ? 'Conoscenza globale' : 'Confermato';
        }

        if ($candidate->review_status === 'ignored') {
            return 'Ignorato';
        }

        $pythonWarnings = data_get($candidate->metadata, 'product_understanding_python.warnings', []);
        $pythonWarnings = is_array($pythonWarnings) ? $pythonWarnings : [];

        /*
        * L'assenza di global facts è un gap di completezza prodotto,
        * non un warning strutturale che richiede un allarme rosso.
        */
        $pythonWarnings = array_values(array_filter(
            $pythonWarnings,
            fn (mixed $warning): bool => $warning !== 'missing_global_facts'
        ));

        $globalFactMatched = data_get($candidate->metadata, 'product_understanding_global_fact.matched') === true;
        $feedbackBias = data_get($candidate->metadata, 'product_understanding_feedback.suggested_bias');

        if ($pythonWarnings !== []) {
            return 'Richiede attenzione';
        }

        if ($globalFactMatched || $currentGlobalFact) {
            return 'Conoscenza globale';
        }

        if (in_array($feedbackBias, ['positive', 'previously_confirmed'], true)) {
            return 'Feedback utile';
        }

        if (($candidate->confidence_score ?? 0) < 80) {
            return 'Bassa affidabilità';
        }

        return 'Standard';
    }

    /**
     * Classi badge conoscenza candidato.
     */
    public function candidateKnowledgeBadgeClasses(ProductIdentificationCandidate $candidate): string
    {
        return match ($this->candidateKnowledgeLabel($candidate)) {
            'Richiede attenzione' => 'bg-red-50 text-red-700 ring-red-600/20',
            'Conoscenza globale' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
            'Feedback utile' => 'bg-green-50 text-green-700 ring-green-600/20',
            'Bassa affidabilità' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
            'Confermato' => 'bg-green-50 text-green-700 ring-green-600/20',
            'Ignorato' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Formatta un segnale tecnico per la UI.
     */
    public function formatSignal(?string $signal): string
    {
        if (! $signal) {
            return '—';
        }

        return ucfirst(str_replace('_', ' ', $signal));
    }

    /**
     * Recupera la diagnostica importi del candidato.
     *
     * Se il metadata non esiste, ricalcola in memoria dai valori già salvati
     * nel candidato. Il fallback è read-only e non aggiorna il database.
     *
     * @return array<string, mixed>
     */
    public function candidateAmountConsistency(ProductIdentificationCandidate $candidate): array
    {
        $stored = data_get($candidate->metadata, 'document_line_amount_consistency', []);

        if (is_array($stored) && $stored !== []) {
            return [
                'source' => 'metadata',
                ...$stored,
            ];
        }

        $quantity = data_get($candidate->metadata, 'quantity');
        $unitPrice = data_get($candidate->metadata, 'unit_price');
        $totalPrice = data_get($candidate->metadata, 'total_price');

        if (
            ($quantity === null || $quantity === '')
            && ($unitPrice === null || $unitPrice === '')
            && ($totalPrice === null || $totalPrice === '')
        ) {
            return [];
        }

        return [
            'version' => 'candidate_amount_review_live_check_v1',
            'source' => 'live_check_not_persisted',
            ...app(DocumentLineAmountConsistencyChecker::class)->check(
                quantity: $quantity,
                unitPrice: $unitPrice,
                totalPrice: $totalPrice,
            ),
        ];
    }

    /**
     * Indica se il candidato deriva da una riga con importi incoerenti.
     */
    public function candidateHasAmountConsistencyMismatch(ProductIdentificationCandidate $candidate): bool
    {
        $amountConsistency = $this->candidateAmountConsistency($candidate);

        return (bool) data_get($amountConsistency, 'checked', false)
            && data_get($amountConsistency, 'is_consistent') === false;
    }

    /**
     * Etichetta sorgente diagnostica importi.
     *
     * @param  array<string, mixed>  $amountConsistency
     */
    public function candidateAmountConsistencySourceLabel(array $amountConsistency): string
    {
        return match ($amountConsistency['source'] ?? null) {
            'metadata' => 'Metadata salvato',
            'live_check_not_persisted' => 'Controllo live non salvato',
            default => '—',
        };
    }

    /**
     * Format uniforme per quantità e importi mostrati in revisione.
     */
    public function formatReviewDecimal(mixed $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (! is_numeric($value)) {
            return '—';
        }

        return number_format((float) $value, $decimals, ',', '.');
    }

    /**
     * Applica al candidato un suggerimento Assisted Review confermato
     * esplicitamente dall'utente.
     *
     * L'azione aggiorna esclusivamente il campo selezionato e i relativi
     * metadata. Non conferma il candidato e non crea alcun prodotto.
     */
    public function acceptAssistedReviewSuggestion(
        int $candidateId,
        string $fieldName,
        AssistedReviewDecisionService $decisionService
    ): void {
        abort_unless(
            Auth::user()?->can('documents.review'),
            403
        );

        $candidate = $this->findReviewableCandidate(
            $candidateId
        );

        try {
            $decisionService->acceptSuggestion(
                candidate: $candidate,
                fieldName: $fieldName,
                userId: (int) Auth::id(),
            );
        } catch (
            InvalidArgumentException | RuntimeException $exception
        ) {
            session()->flash(
                'review_warning',
                $exception->getMessage()
            );

            return;
        }

        $fieldLabel = match ($fieldName) {
            'brand' => 'Brand',
            'category' => 'Categoria',
            'model' => 'Modello',
            default => 'Campo',
        };

        session()->flash(
            'review_success',
            "{$fieldLabel}: suggerimento accettato."
        );
    }

    /**
     * Apre l'editor manuale per un campo Assisted Review.
     *
     * L'editor può essere aperto solamente per campi ancora mancanti
     * oppure per suggerimenti automatici non ancora accettati.
     */
    public function openAssistedReviewManualEditor(
        int $candidateId,
        string $fieldName
    ): void {
        abort_unless(
            Auth::user()?->can('documents.review'),
            403
        );

        if (! $this->isSupportedAssistedReviewField($fieldName)) {
            session()->flash(
                'review_warning',
                'Il campo Assisted Review richiesto non è supportato.'
            );

            return;
        }

        $candidate = $this->findReviewableCandidate(
            $candidateId
        );

        $field = data_get(
            $candidate->metadata,
            "assisted_review.fields.{$fieldName}"
        );

        if (! is_array($field)) {
            session()->flash(
                'review_warning',
                'I dati Assisted Review del candidato non sono validi.'
            );

            return;
        }

        if (! in_array(
            $field['state'] ?? null,
            ['missing', 'suggested'],
            true
        )) {
            session()->flash(
                'review_warning',
                'Questo campo è già stato completato.'
            );

            return;
        }

        $this->resetErrorBag(
            "assistedReviewManualForms.{$candidateId}.{$fieldName}"
        );

        $this->assistedReviewEditingFields[$candidateId][$fieldName] = true;

        /*
        * Non precompiliamo il form con il suggerimento automatico o con un
        * valore corrente non affidabile. L'utente deve inserire o selezionare
        * consapevolmente il valore manuale.
        */
        $this->assistedReviewManualForms[$candidateId][$fieldName] = '';
    }

    /**
     * Chiude un editor manuale senza salvare modifiche.
     */
    public function cancelAssistedReviewManualEditor(
        int $candidateId,
        string $fieldName
    ): void {
        $this->clearAssistedReviewManualEditor(
            candidateId: $candidateId,
            fieldName: $fieldName,
        );
    }

    /**
     * Salva un valore inserito o selezionato manualmente.
     *
     * Il candidato resta pending e non viene creato alcun prodotto.
     */
    public function saveAssistedReviewManualValue(
        int $candidateId,
        string $fieldName,
        AssistedReviewDecisionService $decisionService
    ): void {
        abort_unless(
            Auth::user()?->can('documents.review'),
            403
        );

        if (! $this->isSupportedAssistedReviewField($fieldName)) {
            session()->flash(
                'review_warning',
                'Il campo Assisted Review richiesto non è supportato.'
            );

            return;
        }

        $candidate = $this->findReviewableCandidate(
            $candidateId
        );

        $errorKey = "assistedReviewManualForms.{$candidateId}.{$fieldName}";

        $this->resetErrorBag($errorKey);

        $value = data_get(
            $this->assistedReviewManualForms,
            "{$candidateId}.{$fieldName}"
        );

        try {
            $decisionService->setManualValue(
                candidate: $candidate,
                fieldName: $fieldName,
                value: $value,
                userId: (int) Auth::id(),
            );
        } catch (
            InvalidArgumentException | RuntimeException $exception
        ) {
            /*
            * Mostriamo l'errore accanto al relativo input anziché usare
            * un messaggio generico in cima alla pagina.
            */
            $this->addError(
                $errorKey,
                $exception->getMessage()
            );

            return;
        }

        $this->clearAssistedReviewManualEditor(
            candidateId: $candidateId,
            fieldName: $fieldName,
        );

        session()->flash(
            'review_success',
            $this->assistedReviewFieldLabel($fieldName)
                . ': valore manuale salvato.'
        );
    }

    /**
     * Segna esplicitamente un campo come non disponibile.
     *
     * L'azione rimuove l'eventuale valore operativo non affidabile, ma
     * conserva nei metadata la precedente evidenza per tracciabilità.
     */
    public function declineAssistedReviewField(
        int $candidateId,
        string $fieldName,
        AssistedReviewDecisionService $decisionService
    ): void {
        abort_unless(
            Auth::user()?->can('documents.review'),
            403
        );

        if (! $this->isSupportedAssistedReviewField($fieldName)) {
            session()->flash(
                'review_warning',
                'Il campo Assisted Review richiesto non è supportato.'
            );

            return;
        }

        $candidate = $this->findReviewableCandidate(
            $candidateId
        );

        try {
            $decisionService->declineField(
                candidate: $candidate,
                fieldName: $fieldName,
                userId: (int) Auth::id(),
            );
        } catch (
            InvalidArgumentException | RuntimeException $exception
        ) {
            session()->flash(
                'review_warning',
                $exception->getMessage()
            );

            return;
        }

        $this->clearAssistedReviewManualEditor(
            candidateId: $candidateId,
            fieldName: $fieldName,
        );

        session()->flash(
            'review_success',
            $this->assistedReviewFieldLabel($fieldName)
                . ': campo segnato come non disponibile.'
        );
    }

    /**
     * Conferma rapidamente un candidato dalla pagina revisioni.
     */
    public function confirmCandidate(
        int $candidateId,
        ProductFromCandidateCreator $productFromCandidateCreator
    ): void {
        abort_unless(Auth::user()?->can('documents.review'), 403);

        $candidate = $this->findReviewableCandidate($candidateId);

        if ($candidate->product_id) {
            session()->flash('review_warning', 'Questo candidato ha già generato un prodotto.');

            return;
        }

        if ($candidate->review_status !== 'pending') {
            session()->flash('review_warning', 'Questo candidato è già stato revisionato.');

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
                'review_warning',
                $exception->getMessage()
            );

            return;
        }

        session()->flash('review_success', 'Prodotto creato correttamente: ' . $product->name);

        $this->resetPage();
    }

    /**
     * Ignora rapidamente un candidato dalla pagina revisioni.
     */
    public function ignoreCandidate(
        int $candidateId,
        ProductUnderstandingFeedbackRecorder $feedbackRecorder
    ): void {
        abort_unless(Auth::user()?->can('documents.review'), 403);

        $candidate = $this->findReviewableCandidate($candidateId);

        if ($candidate->product_id) {
            session()->flash('review_warning', 'Questo candidato ha già generato un prodotto e non può essere ignorato.');

            return;
        }

        if ($candidate->review_status === 'ignored') {
            session()->flash('review_warning', 'Questo candidato è già stato ignorato.');

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

        $feedbackRecorder->recordIgnoredCandidate(
            candidate: $candidate,
            userId: (int) Auth::id(),
            reason: 'not_to_register',
            note: null,
        );

        $this->updateDocumentStatusAfterCandidateReview($candidate->document_id);

        session()->flash('review_success', 'Candidato escluso dalla revisione.');

        $this->resetPage();
    }

    /**
     * Verifica che il campo appartenga al contratto Assisted Review v1.
     */
    private function isSupportedAssistedReviewField(
        string $fieldName
    ): bool {
        return in_array(
            $fieldName,
            [
                'brand',
                'category',
                'model',
            ],
            true
        );
    }

    /**
     * Restituisce l'etichetta italiana del campo Assisted Review.
     */
    private function assistedReviewFieldLabel(
        string $fieldName
    ): string {
        return match ($fieldName) {
            'brand' => 'Brand',
            'category' => 'Categoria',
            'model' => 'Modello',
            default => 'Campo',
        };
    }

    /**
     * Rimuove dalla memoria Livewire il form manuale di un campo.
     */
    private function clearAssistedReviewManualEditor(
        int $candidateId,
        string $fieldName
    ): void {
        unset(
            $this->assistedReviewManualForms[$candidateId][$fieldName],
            $this->assistedReviewEditingFields[$candidateId][$fieldName]
        );

        /*
        * Eliminiamo anche i contenitori ormai vuoti per mantenere lo stato
        * Livewire compatto e prevedibile.
        */
        if (
            isset($this->assistedReviewManualForms[$candidateId])
            && $this->assistedReviewManualForms[$candidateId] === []
        ) {
            unset(
                $this->assistedReviewManualForms[$candidateId]
            );
        }

        if (
            isset($this->assistedReviewEditingFields[$candidateId])
            && $this->assistedReviewEditingFields[$candidateId] === []
        ) {
            unset(
                $this->assistedReviewEditingFields[$candidateId]
            );
        }

        $this->resetErrorBag(
            "assistedReviewManualForms.{$candidateId}.{$fieldName}"
        );
    }

    /**
     * Recupera un candidato del workspace corrente.
     */
    private function findReviewableCandidate(int $candidateId): ProductIdentificationCandidate
    {
        return ProductIdentificationCandidate::query()
            ->with(['document'])
            ->whereKey($candidateId)
            ->whereHas('document', fn (Builder $query) => $query->where('team_id', $this->currentTeamId()))
            ->firstOrFail();
    }

    /**
     * Aggiorna lo stato del documento dopo conferma/esclusione candidati.
     */
    private function updateDocumentStatusAfterCandidateReview(int $documentId): void
    {
        $document = Document::query()
            ->where('team_id', $this->currentTeamId())
            ->whereKey($documentId)
            ->firstOrFail();

        $pendingCandidatesCount = $document
            ->productIdentificationCandidates()
            ->where('review_status', 'pending')
            ->whereNull('product_id')
            ->count();

        $confirmedCandidatesCount = $document
            ->productIdentificationCandidates()
            ->where('review_status', 'confirmed')
            ->whereNotNull('product_id')
            ->count();

        $bestConfirmedCandidateScore = $document
            ->productIdentificationCandidates()
            ->where('review_status', 'confirmed')
            ->whereNotNull('product_id')
            ->max('confidence_score');

        $document->update([
            'status' => $pendingCandidatesCount > 0
                ? 'needs_review'
                : ($confirmedCandidatesCount > 0 ? 'linked_to_product' : 'parsed'),
            'product_reliability_score' => $bestConfirmedCandidateScore !== null
                ? (int) $bestConfirmedCandidateScore
                : null,
        ]);
    }

    /**
     * Apre il drawer di dettaglio conoscenza candidato.
     */
    public function openCandidateKnowledgeDrawer(int $candidateId): void
    {
        abort_unless(Auth::user()?->can('documents.review'), 403);

        $candidate = $this->findReviewableCandidate($candidateId);

        $this->selectedCandidateId = $candidate->id;
    }

    /**
     * Chiude il drawer di dettaglio conoscenza candidato.
     */
    public function closeCandidateKnowledgeDrawer(): void
    {
        $this->selectedCandidateId = null;
    }

    /**
     * Candidato selezionato per il drawer.
     */
    public function getSelectedCandidateProperty(): ?ProductIdentificationCandidate
    {
        if (! $this->selectedCandidateId) {
            return null;
        }

        return ProductIdentificationCandidate::query()
            ->with([
                'document.documentType',
                'document.merchant',
                'document.currency',
                'documentLine',
                'product',
                'category',
                'brand',
            ])
            ->whereKey($this->selectedCandidateId)
            ->whereHas('document', fn (Builder $query) => $query->where('team_id', $this->currentTeamId()))
            ->first();
    }

    /**
     * Normalizza liste metadata che possono arrivare come array, stringa o null.
     */
    public function metadataList(mixed $value): array
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
     * Renderizza pagina revisioni.
     */
    public function render(): View
    {
        abort_unless(Auth::user()?->can('documents.review'), 403);

        $baseCandidates = $this->baseCandidateQuery();
        $baseDocuments = $this->baseDocumentQuery();

        $summary = [
            'documents_needing_review' => (clone $baseDocuments)
                ->whereIn('status', ['needs_review', 'low_confidence'])
                ->count(),

            'pending_candidates' => (clone $baseCandidates)
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->count(),

            'low_confidence_candidates' => (clone $baseCandidates)
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->where(function (Builder $query): void {
                    $query->whereNull('confidence_score')
                        ->orWhere('confidence_score', '<', 80);
                })
                ->count(),

            'reviewed_candidates' => (clone $baseCandidates)
                ->whereIn('review_status', ['confirmed', 'ignored'])
                ->count(),
        ];

        $documentsNeedingReview = $this->baseDocumentQuery()
            ->with([
                'documentType',
                'merchant',
                'productIdentificationCandidates',
            ])
            ->whereIn('status', ['needs_review', 'low_confidence'])
            ->latest()
            ->limit(5)
            ->get();

        /*
        * Categorie selezionabili durante la compilazione manuale.
        *
        * Sono visibili le categorie globali e quelle appartenenti al workspace
        * corrente. La selezione effettiva viene comunque verificata nuovamente
        * dal service prima del salvataggio.
        */
        $assistedReviewCategories = Category::query()
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('team_id')
                    ->orWhere(
                        'team_id',
                        $this->currentTeamId()
                    );
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);

        $candidatesQuery = $this->baseCandidateQuery()
            ->with([
                'document.documentType',
                'document.merchant',
                'document.currency',
                'documentLine',
                'product',
                'brand',
                'category',
            ]);

        $this->applyCandidateFilter($candidatesQuery);

        $candidates = $candidatesQuery
            ->latest('document_id')
            ->latest('id')
            ->paginate($this->perPage);

        $assistedReviewPresenter = app(
            AssistedReviewPresenter::class
        );

        $assistedReviewPresentations = $candidates
            ->getCollection()
            ->mapWithKeys(
                fn (
                    ProductIdentificationCandidate $candidate
                ): array => [
                    $candidate->id => $assistedReviewPresenter->present(
                        $candidate
                    ),
                ]
            )
            ->all();

        return view('livewire.reviews.review-index', [
            'summary' => $summary,
            'documentsNeedingReview' => $documentsNeedingReview,
            'candidates' => $candidates,
            'assistedReviewPresentations' => $assistedReviewPresentations,
            'assistedReviewCategories' => $assistedReviewCategories,
        ])->layout('layouts.app');
    }
}