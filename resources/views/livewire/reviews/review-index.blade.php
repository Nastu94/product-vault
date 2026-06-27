{{-- resources/views/livewire/reviews/review-index.blade.php --}}

<div class="py-8">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">
                    Revisioni
                </h1>

                <p class="mt-2 max-w-3xl text-sm text-gray-600">
                    Controlla cosa è stato letto dal documento, cosa viene suggerito
                    da Product Vault e quali campi richiedono una verifica.
                </p>
            </div>
        </div>

        @if (session('review_success'))
            <div class="mb-6 rounded-md bg-green-50 p-4 text-sm text-green-700">
                {{ session('review_success') }}
            </div>
        @endif

        @if (session('review_warning'))
            <div class="mb-6 rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                {{ session('review_warning') }}
            </div>
        @endif

        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Documenti
                </div>
                <div class="mt-2 text-2xl font-semibold text-gray-900">
                    {{ $summary['documents_needing_review'] }}
                </div>
                <div class="mt-1 text-xs text-gray-500">
                    Da controllare
                </div>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Candidati
                </div>
                <div class="mt-2 text-2xl font-semibold text-orange-700">
                    {{ $summary['pending_candidates'] }}
                </div>
                <div class="mt-1 text-xs text-gray-500">
                    In attesa
                </div>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Bassa affidabilità
                </div>
                <div class="mt-2 text-2xl font-semibold text-yellow-700">
                    {{ $summary['low_confidence_candidates'] }}
                </div>
                <div class="mt-1 text-xs text-gray-500">
                    Sotto 80/100
                </div>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Revisionati
                </div>
                <div class="mt-2 text-2xl font-semibold text-green-700">
                    {{ $summary['reviewed_candidates'] }}
                </div>
                <div class="mt-1 text-xs text-gray-500">
                    Confermati o ignorati
                </div>
            </div>
        </div>

        <section class="mb-6 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-medium text-gray-900">
                        Documenti da controllare
                    </h2>

                    <p class="mt-1 text-sm text-gray-600">
                        Documenti con stato <span class="font-medium">needs_review</span> o <span class="font-medium">low_confidence</span>.
                    </p>
                </div>

                <a
                    href="{{ route('documents.index') }}"
                    class="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                >
                    Vai ai documenti
                </a>
            </div>

            @if ($documentsNeedingReview->isNotEmpty())
                <div class="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
                    @foreach ($documentsNeedingReview as $document)
                        <a
                            href="{{ route('documents.show', $document) }}"
                            class="block rounded-lg border border-gray-200 p-4 hover:bg-gray-50"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="line-clamp-1 text-sm font-medium text-gray-900">
                                        {{ $document->original_filename }}
                                    </div>

                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ $document->documentType?->name ?? 'Documento' }}
                                        @if ($document->merchant)
                                            · {{ $document->merchant->name }}
                                        @endif
                                    </div>

                                    <div class="mt-2 text-xs text-gray-500">
                                        {{ $document->productIdentificationCandidates->where('review_status', 'pending')->count() }}
                                        candidati pendenti
                                    </div>
                                </div>

                                <span class="rounded-full bg-orange-50 px-2.5 py-1 text-xs font-medium text-orange-700 ring-1 ring-inset ring-orange-600/20">
                                    {{ str_replace('_', ' ', $document->status) }}
                                </span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="mt-5 rounded-md bg-gray-50 p-4">
                    <p class="text-sm text-gray-700">
                        Nessun documento richiede revisione.
                    </p>
                </div>
            @endif
        </section>

        <section class="rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-200 p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-medium text-gray-900">
                            Candidati prodotto
                        </h2>

                        <p class="mt-1 text-sm text-gray-600">
                            Analizza i candidati proposti dal sistema e apri il documento per confermare, ignorare o correggere.
                        </p>
                    </div>

                    <div>
                        <label for="filter" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                            Filtro
                        </label>

                        <select
                            id="filter"
                            wire:model.live="filter"
                            class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="pending">Da revisionare</option>
                            <option value="needs_completion">Da completare</option>
                            <option value="low_confidence">Bassa affidabilità</option>
                            <option value="python_warnings">Warning Python</option>
                            <option value="amount_mismatch">Importi incoerenti</option>
                            <option value="global_fact">Conoscenza globale</option>
                            <option value="reviewed">Revisionati</option>
                        </select>
                    </div>
                </div>
            </div>

            @if ($candidates->count() > 0)
                <div class="divide-y divide-gray-200">
                    @foreach ($candidates as $candidate)
                        @php
                            $feedback = $candidate->metadata['product_understanding_feedback'] ?? [];
                            $globalFact = $candidate->metadata['product_understanding_global_fact'] ?? [];
                            $python = $candidate->metadata['product_understanding_python'] ?? [];
                            $understanding = $candidate->metadata['product_understanding'] ?? [];
                            $assistedReview = $assistedReviewPresentations[
                                $candidate->id
                            ] ?? [];

                            $reviewSignalPresentation =
                                $reviewSignalPresentations[$candidate->id] ?? [];

                            $reviewSignalGroups = data_get(
                                $reviewSignalPresentation,
                                'primary.groups',
                                []
                            );

                            $reviewSignalSections = [
                                'attention' => [
                                    'title' => 'Controlli consigliati',
                                    'container_classes' =>
                                        'border-yellow-200 bg-yellow-50',
                                    'title_classes' =>
                                        'text-yellow-900',
                                    'message_classes' =>
                                        'text-yellow-800',
                                    'chip_classes' =>
                                        'bg-yellow-100 text-yellow-900 ring-yellow-600/20',
                                ],
                                'missing' => [
                                    'title' => 'Dati da completare',
                                    'container_classes' =>
                                        'border-blue-200 bg-blue-50',
                                    'title_classes' =>
                                        'text-blue-900',
                                    'message_classes' =>
                                        'text-blue-800',
                                    'chip_classes' =>
                                        'bg-blue-100 text-blue-900 ring-blue-600/20',
                                ],
                                'positive' => [
                                    'title' => 'Dati coerenti',
                                    'container_classes' =>
                                        'border-green-200 bg-green-50',
                                    'title_classes' =>
                                        'text-green-900',
                                    'message_classes' =>
                                        'text-green-800',
                                    'chip_classes' =>
                                        'bg-green-100 text-green-900 ring-green-600/20',
                                ],
                            ];

                            $primaryReviewSignalCount = (int) data_get(
                                $reviewSignalPresentation,
                                'counts.primary',
                                0
                            );

                            $confirmationState =
                                $assistedReviewConfirmationStates[
                                    $candidate->id
                                ] ?? [
                                    'allowed' => true,
                                    'reason' => 'not_evaluated',
                                    'unresolved_fields' => [],
                                    'message' => null,
                                ];

                            $confirmationBlocked =
                                ($confirmationState['allowed'] ?? true) !== true;

                            $confirmationHasOptionalCompletion =
                                ($confirmationState['reason'] ?? null)
                                    === 'assisted_review_optional_completion'
                                && ($confirmationState['unresolved_fields'] ?? [])
                                    !== [];

                            $assistedReviewFields = is_array(
                                $assistedReview['fields'] ?? null
                            )
                                ? $assistedReview['fields']
                                : [];
                            $amountConsistency = $this->candidateAmountConsistency($candidate);
                            $hasAmountConsistencyMismatch = $this->candidateHasAmountConsistencyMismatch($candidate);
                            $currentGlobalFact =
                                $this->candidateCurrentGlobalFact($candidate);

                            $pythonSimilarity =
                                $python['best_match']['similarity'] ?? null;

                            $showPythonBestMatch =
                                $pythonSimilarity !== null
                                && (float) $pythonSimilarity >= 70;
                        @endphp

                        <article class="p-6">
                            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold text-gray-900">
                                            {{ $candidate->name }}
                                        </h3>

                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->candidateReviewStatusBadgeClasses($candidate) }}">
                                            {{ $this->candidateReviewStatusLabel($candidate) }}
                                        </span>

                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->candidateKnowledgeBadgeClasses($candidate) }}">
                                            {{ $this->candidateKnowledgeLabel($candidate) }}
                                        </span>

                                        @if ($hasAmountConsistencyMismatch)
                                            <span class="inline-flex items-center rounded-full bg-yellow-50 px-2.5 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">
                                                Importi non coerenti
                                            </span>
                                        @endif
                                    </div>

                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                        <span>Documento: {{ $candidate->document?->original_filename ?? '—' }}</span>

                                        @if ($candidate->document?->documentType)
                                            <span>Tipo: {{ $candidate->document->documentType->name }}</span>
                                        @endif

                                        @if ($candidate->document?->merchant)
                                            <span>Venditore: {{ $candidate->document->merchant->name }}</span>
                                        @endif

                                        @if ($candidate->price)
                                            <span>Prezzo: {{ number_format((float) $candidate->price, 2, ',', '.') }} {{ $candidate->document?->currency?->code }}</span>
                                        @endif

                                        @if ($candidate->confidence_score !== null)
                                            <span>Affidabilità: {{ $candidate->confidence_score }}/100</span>
                                        @endif
                                    </div>

                                    @if ($candidate->documentLine)
                                        <div class="mt-4 rounded-md bg-gray-50 p-3">
                                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Riga documento
                                            </div>

                                            <p class="mt-1 text-sm text-gray-700">
                                                {{ $candidate->documentLine->description }}
                                            </p>
                                        </div>
                                    @endif

                                    @if ($hasAmountConsistencyMismatch)
                                        <div class="mt-3 rounded-md bg-yellow-50 p-3 ring-1 ring-inset ring-yellow-600/20">
                                            <div class="text-sm font-medium text-yellow-900">
                                                Importi riga non coerenti
                                            </div>

                                            <p class="mt-1 text-sm text-yellow-800">
                                                Quantità × prezzo unitario non corrisponde al totale riga. Verifica gli importi prima di confermare il candidato.
                                            </p>

                                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-yellow-800">
                                                <span>Quantità: {{ $this->formatReviewDecimal(data_get($candidate->metadata, 'quantity'), 3) }}</span>
                                                <span>Unitario: {{ $this->formatReviewDecimal(data_get($candidate->metadata, 'unit_price')) }}</span>
                                                <span>Totale: {{ $this->formatReviewDecimal(data_get($candidate->metadata, 'total_price')) }}</span>
                                                <span>Atteso: {{ $this->formatReviewDecimal(data_get($amountConsistency, 'expected_total')) }}</span>
                                                <span>Scarto: {{ $this->formatReviewDecimal(data_get($amountConsistency, 'delta')) }}</span>
                                                <span>Sorgente: {{ $this->candidateAmountConsistencySourceLabel($amountConsistency) }}</span>
                                            </div>
                                        </div>
                                    @endif

                                    @if (($assistedReview['available'] ?? false) === true)
                                        <section class="mt-4 rounded-lg border border-indigo-100 bg-indigo-50/30 p-4">
                                            <div class="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <h4 class="text-sm font-medium text-gray-900">
                                                        {{ ($assistedReview['needs_user_completion'] ?? false)
                                                            ? 'Dati prodotto opzionali da verificare'
                                                            : 'Dati prodotto' }}
                                                    </h4>

                                                    <p class="mt-1 text-xs text-gray-600">
                                                        @if (($assistedReview['needs_user_completion'] ?? false) === true)
                                                            Product Vault ha individuato
                                                            {{ $assistedReview['completion_count'] }}
                                                            {{ ($assistedReview['completion_count'] ?? 0) === 1
                                                                ? 'campo opzionale da completare o verificare.'
                                                                : 'campi opzionali da completare o verificare.' }}
                                                            Puoi comunque confermare il prodotto: i valori non
                                                            confermati resteranno vuoti.
                                                        @else
                                                            Brand, categoria e modello risultano già disponibili.
                                                        @endif
                                                    </p>
                                                </div>

                                                @if (($assistedReview['needs_user_completion'] ?? false) === true)
                                                    <span class="inline-flex items-center rounded-full bg-orange-50 px-2.5 py-1 text-xs font-medium text-orange-700 ring-1 ring-inset ring-orange-600/20">
                                                        Opzionali:
                                                        {{ $assistedReview['completion_count'] }}
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                                        Dati presenti
                                                    </span>
                                                @endif
                                            </div>

                                            @if ($confirmationBlocked)
                                                <div class="mt-4 rounded-md border border-red-200 bg-red-50 p-3">
                                                    <p class="text-sm font-medium text-red-900">
                                                        Conferma non disponibile
                                                    </p>

                                                    <p class="mt-1 text-xs text-red-800">
                                                        {{ $confirmationState['message']
                                                            ?? 'Il candidato non può essere confermato.' }}
                                                    </p>
                                                </div>
                                            @elseif ($confirmationHasOptionalCompletion)
                                                <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 p-3">
                                                    <p class="text-sm font-medium text-blue-900">
                                                        Conferma disponibile
                                                    </p>

                                                    <p class="mt-1 text-xs text-blue-800">
                                                        {{ $confirmationState['message'] }}
                                                    </p>
                                                </div>
                                            @endif

                                            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                                @foreach (['brand', 'category', 'model'] as $fieldName)
                                                    @php
                                                        $field = $assistedReviewFields[$fieldName] ?? [];
                                                        $displayValue = $field['display_value'] ?? null;
                                                        $hasUnreliableCurrent = (
                                                            $field['has_unreliable_current'] ?? false
                                                        ) === true;
                                                    @endphp

                                                    <div class="rounded-md bg-white p-3 ring-1 ring-gray-200">
                                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                                {{ $field['label'] ?? ucfirst($fieldName) }}
                                                            </div>

                                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $this->assistedReviewStateBadgeClasses($field) }}">
                                                                {{ $field['state_label'] ?? 'Da completare' }}
                                                            </span>
                                                        </div>

                                                        @if ($displayValue !== null)
                                                            <div class="mt-3 text-sm font-medium text-gray-900">
                                                                {{ $displayValue }}
                                                            </div>

                                                            @if (($field['state'] ?? null) === 'suggested')
                                                                <p class="mt-1 text-xs text-indigo-700">
                                                                    Proposta non ancora applicata.
                                                                </p>
                                                            @endif
                                                        @elseif ($hasUnreliableCurrent)
                                                            <div class="mt-3 text-sm text-gray-900">
                                                                Valore letto:
                                                                <span class="font-medium">
                                                                    {{ $field['current_value'] }}
                                                                </span>
                                                            </div>

                                                            <p class="mt-1 text-xs text-orange-700">
                                                                Da verificare: non viene usato come valore valido.
                                                            </p>
                                                        @else
                                                            <div class="mt-3 text-sm text-gray-500">
                                                                Non disponibile
                                                            </div>
                                                        @endif
                                                        @php
                                                            $assistedReviewFieldState =
                                                                $field['state'] ?? 'missing';

                                                            $assistedReviewFieldIsActionable = in_array(
                                                                $assistedReviewFieldState,
                                                                ['missing', 'suggested'],
                                                                true
                                                            );

                                                            $assistedReviewFieldIsEditing = (bool) data_get(
                                                                $assistedReviewEditingFields,
                                                                "{$candidate->id}.{$fieldName}",
                                                                false
                                                            );

                                                            $assistedReviewManualErrorKey =
                                                                "assistedReviewManualForms.{$candidate->id}.{$fieldName}";
                                                        @endphp

                                                        @if (
                                                            $assistedReviewFieldIsActionable
                                                            && $candidate->review_status === 'pending'
                                                            && $candidate->product_id === null
                                                        )
                                                            @if ($assistedReviewFieldIsEditing)
                                                                <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-3">
                                                                    <label
                                                                        for="assisted-review-manual-{{ $candidate->id }}-{{ $fieldName }}"
                                                                        class="block text-xs font-medium text-gray-700"
                                                                    >
                                                                        @switch($fieldName)
                                                                            @case('brand')
                                                                                Inserisci il brand
                                                                                @break

                                                                            @case('category')
                                                                                Seleziona la categoria
                                                                                @break

                                                                            @case('model')
                                                                                Inserisci il modello
                                                                                @break

                                                                            @default
                                                                                Inserisci il valore
                                                                        @endswitch
                                                                    </label>

                                                                    @if ($fieldName === 'category')
                                                                        <select
                                                                            id="assisted-review-manual-{{ $candidate->id }}-{{ $fieldName }}"
                                                                            wire:model.defer="assistedReviewManualForms.{{ $candidate->id }}.{{ $fieldName }}"
                                                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                                        >
                                                                            <option value="">
                                                                                Seleziona una categoria
                                                                            </option>

                                                                            @foreach ($assistedReviewCategories as $categoryOption)
                                                                                <option value="{{ $categoryOption->id }}">
                                                                                    {{ $categoryOption->name }}
                                                                                </option>
                                                                            @endforeach
                                                                        </select>
                                                                    @else
                                                                        <input
                                                                            id="assisted-review-manual-{{ $candidate->id }}-{{ $fieldName }}"
                                                                            type="text"
                                                                            wire:model.defer="assistedReviewManualForms.{{ $candidate->id }}.{{ $fieldName }}"
                                                                            autocomplete="off"
                                                                            @if ($fieldName === 'brand')
                                                                                placeholder="Esempio: NetWave"
                                                                            @elseif ($fieldName === 'model')
                                                                                placeholder="Esempio: NX-500"
                                                                            @endif
                                                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                                        >
                                                                    @endif

                                                                    @error($assistedReviewManualErrorKey)
                                                                        <p class="mt-1 text-xs text-red-600">
                                                                            {{ $message }}
                                                                        </p>
                                                                    @enderror

                                                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                                                        <button
                                                                            type="button"
                                                                            wire:click="saveAssistedReviewManualValue({{ $candidate->id }}, '{{ $fieldName }}')"
                                                                            wire:loading.attr="disabled"
                                                                            wire:target="saveAssistedReviewManualValue({{ $candidate->id }}, '{{ $fieldName }}')"
                                                                            class="inline-flex items-center justify-center rounded-md border border-transparent bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                                                        >
                                                                            Salva valore
                                                                        </button>

                                                                        <button
                                                                            type="button"
                                                                            wire:click="cancelAssistedReviewManualEditor({{ $candidate->id }}, '{{ $fieldName }}')"
                                                                            wire:loading.attr="disabled"
                                                                            wire:target="saveAssistedReviewManualValue({{ $candidate->id }}, '{{ $fieldName }}')"
                                                                            class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                                        >
                                                                            Annulla
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            @else
                                                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                                                    @if (
                                                                        $assistedReviewFieldState === 'suggested'
                                                                        && ($field['can_accept_suggestion'] ?? false) === true
                                                                    )
                                                                        <button
                                                                            type="button"
                                                                            wire:key="accept-assisted-review-{{ $candidate->id }}-{{ $fieldName }}"
                                                                            wire:click="acceptAssistedReviewSuggestion({{ $candidate->id }}, '{{ $fieldName }}')"
                                                                            wire:loading.attr="disabled"
                                                                            wire:target="acceptAssistedReviewSuggestion({{ $candidate->id }}, '{{ $fieldName }}')"
                                                                            class="inline-flex items-center justify-center rounded-md border border-indigo-200 bg-white px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                                        >
                                                                            Accetta suggerimento
                                                                        </button>
                                                                    @endif

                                                                    <button
                                                                        type="button"
                                                                        wire:click="openAssistedReviewManualEditor({{ $candidate->id }}, '{{ $fieldName }}')"
                                                                        wire:loading.attr="disabled"
                                                                        wire:target="openAssistedReviewManualEditor({{ $candidate->id }}, '{{ $fieldName }}')"
                                                                        class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                                    >
                                                                        {{ $assistedReviewFieldState === 'suggested'
                                                                            ? 'Usa un altro valore'
                                                                            : 'Inserisci manualmente' }}
                                                                    </button>

                                                                    <button
                                                                        type="button"
                                                                        wire:click="declineAssistedReviewField({{ $candidate->id }}, '{{ $fieldName }}')"
                                                                        wire:confirm="Confermi che questo dato non è disponibile?"
                                                                        wire:loading.attr="disabled"
                                                                        wire:target="declineAssistedReviewField({{ $candidate->id }}, '{{ $fieldName }}')"
                                                                        class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                                    >
                                                                        Non disponibile
                                                                    </button>
                                                                </div>
                                                            @endif
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </section>
                                    @endif

                                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                                        <div class="rounded-md border border-gray-200 p-4">
                                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Identificativi
                                            </div>

                                            <dl class="mt-3 space-y-2 text-sm">
                                                <div>
                                                    <dt class="text-xs text-gray-500">
                                                        EAN
                                                    </dt>
                                                    <dd class="text-gray-900">
                                                        {{ $candidate->ean_code ?? '—' }}
                                                    </dd>
                                                </div>

                                                <div>
                                                    <dt class="text-xs text-gray-500">
                                                        Seriale
                                                    </dt>
                                                    <dd class="text-gray-900">
                                                        {{ $candidate->serial_number ?? '—' }}
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>

                                        <div class="rounded-md border border-gray-200 p-4">
                                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Knowledge
                                            </div>

                                            <dl class="mt-3 space-y-2 text-sm">
                                                <div>
                                                    <dt class="text-xs text-gray-500">Feedback</dt>
                                                    <dd class="text-gray-900">
                                                        {{ $feedback['suggested_bias'] ?? '—' }}
                                                        @if ($feedback['review_hint'] ?? null)
                                                            <span class="block text-xs text-gray-500">
                                                                {{ $this->formatSignal($feedback['review_hint']) }}
                                                            </span>
                                                        @endif
                                                    </dd>
                                                </div>

                                                <div>
                                                    <dt class="text-xs text-gray-500">Global fact</dt>
                                                    <dd class="text-gray-900">
                                                        @if ($currentGlobalFact)
                                                            {{ $currentGlobalFact->canonical_name ?? 'Match globale EAN' }}

                                                            <span class="block text-xs text-gray-500">
                                                                EAN {{ $currentGlobalFact->fact_value }}
                                                                · conferme {{ $currentGlobalFact->confirmed_count }}
                                                                · ignorati {{ $currentGlobalFact->ignored_count }}
                                                            </span>
                                                        @elseif (($globalFact['matched'] ?? false) === true)
                                                            {{ $globalFact['canonical_name'] ?? 'Match globale' }}
                                                        @else
                                                            Nessun match
                                                        @endif
                                                    </dd>
                                                </div>

                                                <div>
                                                    <dt class="text-xs text-gray-500">Python best match</dt>
                                                    <dd class="text-gray-900">
                                                        @if ($showPythonBestMatch)
                                                            {{ $python['best_match']['canonical_name'] ?? '—' }}

                                                            <span class="block text-xs text-gray-500">
                                                                Similarità {{ $pythonSimilarity }}
                                                            </span>
                                                        @elseif ($python['best_match']['canonical_name'] ?? null)
                                                            <span class="text-gray-500">
                                                                Nessun match affidabile
                                                            </span>

                                                            <span class="block text-xs text-gray-400">
                                                                Match diagnostico ignorato:
                                                                {{ $python['best_match']['canonical_name'] }}
                                                                · similarità {{ $pythonSimilarity }}
                                                            </span>
                                                        @else
                                                            —
                                                        @endif
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>

                                        <div class="rounded-md border border-gray-200 p-4">
                                            <div class="flex items-center justify-between gap-3">
                                                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                    Indicazioni di revisione
                                                </div>

                                                @if ($primaryReviewSignalCount > 0)
                                                    <span class="inline-flex shrink-0 items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                                        {{ $primaryReviewSignalCount }}
                                                    </span>
                                                @endif
                                            </div>

                                            <div class="mt-3 space-y-2">
                                                @php
                                                    $hasPrimaryReviewSignals = false;
                                                @endphp

                                                @foreach ($reviewSignalSections as $group => $section)
                                                    @php
                                                        $groupItems = $reviewSignalGroups[$group] ?? [];
                                                        $groupItems = is_array($groupItems)
                                                            ? $groupItems
                                                            : [];
                                                    @endphp

                                                    @if ($groupItems !== [])
                                                        @php
                                                            $hasPrimaryReviewSignals = true;
                                                        @endphp

                                                        <section
                                                            class="rounded-md border p-2.5 {{ $section['container_classes'] }}"
                                                        >
                                                            <div class="flex items-center justify-between gap-2">
                                                                <div
                                                                    class="text-xs font-medium uppercase tracking-wider {{ $section['title_classes'] }}"
                                                                >
                                                                    {{ $section['title'] }}
                                                                </div>

                                                                @if (count($groupItems) > 1)
                                                                    <span
                                                                        class="shrink-0 text-xs font-medium {{ $section['message_classes'] }}"
                                                                    >
                                                                        {{ count($groupItems) }}
                                                                    </span>
                                                                @endif
                                                            </div>

                                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                                @foreach ($groupItems as $item)
                                                                    <span
                                                                        class="inline-flex max-w-full items-center rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $section['chip_classes'] }}"
                                                                    >
                                                                        {{ $item['title'] }}
                                                                    </span>
                                                                @endforeach
                                                            </div>

                                                            <details class="mt-2">
                                                                <summary
                                                                    class="cursor-pointer select-none text-xs font-medium {{ $section['message_classes'] }}"
                                                                >
                                                                    Mostra spiegazioni
                                                                </summary>

                                                                <div class="mt-2 space-y-3 border-t border-black/5 pt-2">
                                                                    @foreach ($groupItems as $item)
                                                                        <div>
                                                                            <div
                                                                                class="text-xs font-medium {{ $section['title_classes'] }}"
                                                                            >
                                                                                {{ $item['title'] }}
                                                                            </div>

                                                                            <p
                                                                                class="mt-1 text-xs leading-5 {{ $section['message_classes'] }}"
                                                                            >
                                                                                {{ $item['message'] }}
                                                                            </p>

                                                                            @if ($item['action'] ?? null)
                                                                                <p
                                                                                    class="mt-1 text-xs font-medium leading-5 {{ $section['message_classes'] }}"
                                                                                >
                                                                                    {{ $item['action'] }}
                                                                                </p>
                                                                            @endif
                                                                        </div>
                                                                    @endforeach
                                                                </div>
                                                            </details>
                                                        </section>
                                                    @endif
                                                @endforeach

                                                @unless ($hasPrimaryReviewSignals)
                                                    <p class="text-sm text-gray-500">
                                                        Nessuna verifica aggiuntiva richiesta.
                                                    </p>
                                                @endunless
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex shrink-0 flex-col gap-2 lg:w-40">
                                    @if ($candidate->review_status === 'pending' && ! $candidate->product_id)
                                        @if ($confirmationBlocked)
                                            <button
                                                type="button"
                                                disabled
                                                title="{{ $confirmationState['message']
                                                    ?? 'Completa i dati prodotto prima di confermare.' }}"
                                                class="inline-flex cursor-not-allowed items-center justify-center rounded-md bg-gray-200 px-3 py-2 text-sm font-medium text-gray-500"
                                            >
                                                Completa dati
                                            </button>

                                            <p class="text-center text-xs text-orange-700">
                                                Risolvi i campi indicati prima di creare il prodotto.
                                            </p>
                                        @else
                                            <button
                                                type="button"
                                                wire:click="confirmCandidate({{ $candidate->id }})"
                                                wire:confirm="Confermare questo candidato e creare la scheda prodotto?"
                                                wire:loading.attr="disabled"
                                                wire:target="confirmCandidate({{ $candidate->id }})"
                                                class="inline-flex items-center justify-center rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                Conferma
                                            </button>
                                            @if ($confirmationHasOptionalCompletion)
                                                <p class="text-center text-xs text-blue-700">
                                                    Verranno salvati soltanto i dati già presenti o
                                                    confermati.
                                                </p>
                                            @endif
                                        @endif

                                        <button
                                            type="button"
                                            wire:click="ignoreCandidate({{ $candidate->id }})"
                                            wire:confirm="Ignorare questo candidato? Verrà mantenuto nello storico ma non genererà un prodotto."
                                            class="inline-flex items-center justify-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                        >
                                            Ignora
                                        </button>
                                    @endif

                                    <button
                                        type="button"
                                        wire:click="openCandidateKnowledgeDrawer({{ $candidate->id }})"
                                        class="inline-flex items-center justify-center rounded-md border border-indigo-200 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50"
                                    >
                                        Dettaglio conoscenza
                                    </button>

                                    @if ($candidate->document)
                                        <a
                                            href="{{ route('documents.show', $candidate->document) }}"
                                            class="inline-flex items-center justify-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                        >
                                            Apri revisione
                                        </a>
                                    @endif

                                    @if ($candidate->product)
                                        <a
                                            href="{{ route('products.show', $candidate->product) }}"
                                            class="inline-flex items-center justify-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                        >
                                            Apri prodotto
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="border-t border-gray-200 px-6 py-4">
                    {{ $candidates->links() }}
                </div>
            @else
                <div class="p-8 text-center">
                    <h2 class="text-base font-medium text-gray-900">
                        Nessun candidato trovato
                    </h2>

                    <p class="mt-2 text-sm text-gray-600">
                        I candidati da revisionare compariranno qui dopo il caricamento e l’analisi dei documenti.
                    </p>

                    <div class="mt-6">
                        <a
                            href="{{ route('documents.index') }}"
                            class="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                        >
                            Vai ai documenti
                        </a>
                    </div>
                </div>
            @endif
        </section>
    </div>
    @if ($this->selectedCandidate)
        @php
            $selectedCandidate = $this->selectedCandidate;

            $selectedFeedback = $selectedCandidate->metadata['product_understanding_feedback'] ?? [];
            $selectedGlobalFactSnapshot = $selectedCandidate->metadata['product_understanding_global_fact'] ?? [];
            $selectedPython = $selectedCandidate->metadata['product_understanding_python'] ?? [];
            $selectedUnderstanding = $selectedCandidate->metadata['product_understanding'] ?? [];
            $selectedAmountConsistency = $this->candidateAmountConsistency($selectedCandidate);
            $selectedHasAmountConsistencyMismatch = $this->candidateHasAmountConsistencyMismatch($selectedCandidate);

            $selectedCurrentGlobalFact = $this->candidateCurrentGlobalFact($selectedCandidate);

            $selectedReviewSignalPresentation =
                $this->selectedCandidateReviewSignalPresentation;

            $selectedReviewSignalGroups = data_get(
                $selectedReviewSignalPresentation,
                'primary.groups',
                []
            );

            $selectedReviewSignalDiagnostics = data_get(
                $selectedReviewSignalPresentation,
                'diagnostics.items',
                []
            );

            $selectedSuppressedReviewSignalDuplicates = data_get(
                $selectedReviewSignalPresentation,
                'diagnostics.suppressed_duplicates',
                []
            );

            $selectedReviewSignalGroups = is_array(
                $selectedReviewSignalGroups
            )
                ? $selectedReviewSignalGroups
                : [];

            $selectedReviewSignalDiagnostics = is_array(
                $selectedReviewSignalDiagnostics
            )
                ? $selectedReviewSignalDiagnostics
                : [];

            $selectedSuppressedReviewSignalDuplicates = is_array(
                $selectedSuppressedReviewSignalDuplicates
            )
                ? $selectedSuppressedReviewSignalDuplicates
                : [];

            $selectedReviewSignalSections = [
                'attention' => [
                    'title' => 'Controlli consigliati',
                    'container_classes' =>
                        'border-yellow-200 bg-yellow-50',
                    'title_classes' =>
                        'text-yellow-900',
                    'message_classes' =>
                        'text-yellow-800',
                ],
                'missing' => [
                    'title' => 'Dati da completare',
                    'container_classes' =>
                        'border-blue-200 bg-blue-50',
                    'title_classes' =>
                        'text-blue-900',
                    'message_classes' =>
                        'text-blue-800',
                ],
                'positive' => [
                    'title' => 'Dati coerenti',
                    'container_classes' =>
                        'border-green-200 bg-green-50',
                    'title_classes' =>
                        'text-green-900',
                    'message_classes' =>
                        'text-green-800',
                ],
            ];

            $selectedIdentityGuardrails = $selectedPython['best_match']['identity_guardrails'] ?? [];
            $selectedPythonBestMatch = $selectedPython['best_match'] ?? [];
        @endphp

        <div class="fixed inset-0 z-50 overflow-hidden" role="dialog" aria-modal="true">
            <div
                class="absolute inset-0 bg-gray-900/40"
                wire:click="closeCandidateKnowledgeDrawer"
            ></div>

            <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10">
                <div class="pointer-events-auto w-screen max-w-3xl">
                    <div class="flex h-full flex-col overflow-y-auto bg-white shadow-xl">
                        <div class="border-b border-gray-200 px-6 py-5">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <h2 class="text-lg font-semibold text-gray-900">
                                        Dettaglio conoscenza
                                    </h2>

                                    <p class="mt-1 truncate text-sm text-gray-600">
                                        {{ $selectedCandidate->name }}
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    wire:click="closeCandidateKnowledgeDrawer"
                                    class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>

                        <div class="space-y-6 px-6 py-6">
                            <section class="rounded-lg border border-gray-200 p-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->candidateReviewStatusBadgeClasses($selectedCandidate) }}">
                                        {{ $this->candidateReviewStatusLabel($selectedCandidate) }}
                                    </span>

                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->candidateKnowledgeBadgeClasses($selectedCandidate) }}">
                                        {{ $this->candidateKnowledgeLabel($selectedCandidate) }}
                                    </span>

                                    @if ($selectedHasAmountConsistencyMismatch)
                                        <span class="inline-flex items-center rounded-full bg-yellow-50 px-2.5 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">
                                            Importi non coerenti
                                        </span>
                                    @endif
                                </div>

                                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Nome candidato
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $selectedCandidate->name }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Affidabilità
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $selectedCandidate->confidence_score !== null ? $selectedCandidate->confidence_score . '/100' : '—' }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Modello
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $selectedCandidate->model ?? '—' }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            EAN
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $selectedCandidate->ean_code ?? '—' }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Seriale
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $selectedCandidate->serial_number ?? '—' }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Prezzo
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            @if ($selectedCandidate->price)
                                                {{ number_format((float) $selectedCandidate->price, 2, ',', '.') }}
                                                {{ $selectedCandidate->document?->currency?->code }}
                                            @else
                                                —
                                            @endif
                                        </dd>
                                    </div>
                                </dl>
                            </section>

                            <section class="rounded-lg border border-gray-200 p-4">
                                <h3 class="text-sm font-semibold text-gray-900">
                                    Origine nel documento
                                </h3>

                                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Documento
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            @if ($selectedCandidate->document)
                                                <a
                                                    href="{{ route('documents.show', $selectedCandidate->document) }}"
                                                    class="text-indigo-600 hover:text-indigo-900"
                                                >
                                                    {{ $selectedCandidate->document->original_filename }}
                                                </a>
                                            @else
                                                —
                                            @endif
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Tipo documento
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $selectedCandidate->document?->documentType?->name ?? '—' }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Venditore
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $selectedCandidate->document?->merchant?->name ?? '—' }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Data acquisto
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $selectedCandidate->document?->purchase_date?->format('d/m/Y') ?? '—' }}
                                        </dd>
                                    </div>
                                </dl>

                                @if ($selectedCandidate->documentLine)
                                    <div class="mt-4 rounded-md bg-gray-50 p-3">
                                        <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Riga documento
                                        </div>

                                        <p class="mt-1 text-sm text-gray-700">
                                            {{ $selectedCandidate->documentLine->description }}
                                        </p>

                                        <div class="mt-2 text-xs text-gray-500">
                                            Quantità:
                                            {{ $selectedCandidate->documentLine->quantity ?? '—' }}
                                            · Prezzo unitario:
                                            {{ $selectedCandidate->documentLine->unit_price ?? '—' }}
                                            · Totale:
                                            {{ $selectedCandidate->documentLine->total_price ?? '—' }}
                                        </div>
                                    </div>
                                @endif

                                @if ($selectedHasAmountConsistencyMismatch)
                                    <div class="mt-4 rounded-md bg-yellow-50 p-4 ring-1 ring-inset ring-yellow-600/20">
                                        <h4 class="text-sm font-semibold text-yellow-900">
                                            Importi riga non coerenti
                                        </h4>

                                        <p class="mt-1 text-sm text-yellow-800">
                                            La riga che ha generato questo candidato ha una differenza tra quantità × prezzo unitario e totale riga.
                                            Il candidato non viene bloccato automaticamente, ma va verificato prima della conferma.
                                        </p>

                                        <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                            <div>
                                                <dt class="text-xs font-medium uppercase tracking-wider text-yellow-700">Quantità</dt>
                                                <dd class="mt-1 text-yellow-900">
                                                    {{ $this->formatReviewDecimal(data_get($selectedCandidate->metadata, 'quantity'), 3) }}
                                                </dd>
                                            </div>

                                            <div>
                                                <dt class="text-xs font-medium uppercase tracking-wider text-yellow-700">Prezzo unitario</dt>
                                                <dd class="mt-1 text-yellow-900">
                                                    {{ $this->formatReviewDecimal(data_get($selectedCandidate->metadata, 'unit_price')) }}
                                                </dd>
                                            </div>

                                            <div>
                                                <dt class="text-xs font-medium uppercase tracking-wider text-yellow-700">Totale riga</dt>
                                                <dd class="mt-1 text-yellow-900">
                                                    {{ $this->formatReviewDecimal(data_get($selectedCandidate->metadata, 'total_price')) }}
                                                </dd>
                                            </div>

                                            <div>
                                                <dt class="text-xs font-medium uppercase tracking-wider text-yellow-700">Totale atteso</dt>
                                                <dd class="mt-1 text-yellow-900">
                                                    {{ $this->formatReviewDecimal(data_get($selectedAmountConsistency, 'expected_total')) }}
                                                </dd>
                                            </div>

                                            <div>
                                                <dt class="text-xs font-medium uppercase tracking-wider text-yellow-700">Scarto</dt>
                                                <dd class="mt-1 text-yellow-900">
                                                    {{ $this->formatReviewDecimal(data_get($selectedAmountConsistency, 'delta')) }}
                                                </dd>
                                            </div>

                                            <div>
                                                <dt class="text-xs font-medium uppercase tracking-wider text-yellow-700">Sorgente</dt>
                                                <dd class="mt-1 text-yellow-900">
                                                    {{ $this->candidateAmountConsistencySourceLabel($selectedAmountConsistency) }}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                @endif
                            </section>

                            <section class="rounded-lg border border-gray-200 p-4">
                                <h3 class="text-sm font-semibold text-gray-900">
                                    Conoscenza globale
                                </h3>

                                @if ($selectedCurrentGlobalFact)
                                    <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Nome canonico attuale
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                {{ $selectedCurrentGlobalFact->canonical_name }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Fact
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                {{ $selectedCurrentGlobalFact->fact_type }}:
                                                {{ $selectedCurrentGlobalFact->fact_value }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Conteggi globali
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                Visti {{ $selectedCurrentGlobalFact->seen_count }}
                                                · confermati {{ $selectedCurrentGlobalFact->confirmed_count }}
                                                · ignorati {{ $selectedCurrentGlobalFact->ignored_count }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Score globale
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                {{ $selectedCurrentGlobalFact->global_product_confidence_score ?? '—' }}
                                            </dd>
                                        </div>
                                    </dl>
                                @else
                                    <div class="mt-4 rounded-md bg-gray-50 p-3">
                                        <p class="text-sm text-gray-700">
                                            Nessun global fact attuale trovato per questo candidato.
                                        </p>
                                    </div>
                                @endif

                                @if ($selectedGlobalFactSnapshot)
                                    <details class="mt-4">
                                        <summary class="cursor-pointer text-sm font-medium text-gray-700">
                                            Snapshot global fact salvato nel candidato
                                        </summary>

                                        <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                            <div>
                                                <dt class="text-xs text-gray-500">Matched</dt>
                                                <dd>{{ ($selectedGlobalFactSnapshot['matched'] ?? false) ? 'Sì' : 'No' }}</dd>
                                            </div>

                                            <div>
                                                <dt class="text-xs text-gray-500">Nome canonico snapshot</dt>
                                                <dd>{{ $selectedGlobalFactSnapshot['canonical_name'] ?? '—' }}</dd>
                                            </div>

                                            <div>
                                                <dt class="text-xs text-gray-500">Tipo match</dt>
                                                <dd>{{ $selectedGlobalFactSnapshot['match_type'] ?? '—' }}</dd>
                                            </div>

                                            <div>
                                                <dt class="text-xs text-gray-500">Score snapshot</dt>
                                                <dd>{{ $selectedGlobalFactSnapshot['global_product_confidence_score'] ?? '—' }}</dd>
                                            </div>
                                        </dl>
                                    </details>
                                @endif
                            </section>

                            <section class="rounded-lg border border-gray-200 p-4">
                                <h3 class="text-sm font-semibold text-gray-900">
                                    Feedback e preferenze
                                </h3>

                                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Bias suggerito
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $selectedFeedback['suggested_bias'] ?? '—' }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Review hint
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $this->formatSignal($selectedFeedback['review_hint'] ?? null) }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Product identity score
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $selectedFeedback['product_identity_score'] ?? '—' }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Registration preference score
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $selectedFeedback['registration_preference_score'] ?? '—' }}
                                        </dd>
                                    </div>
                                </dl>
                            </section>

                            <section class="rounded-lg border border-gray-200 p-4">
                                <h3 class="text-sm font-semibold text-gray-900">
                                    Analisi Python
                                </h3>

                                @if ($selectedPythonBestMatch)
                                    <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Best match
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                {{ $selectedPythonBestMatch['canonical_name'] ?? '—' }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Similarità
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                {{ $selectedPythonBestMatch['similarity'] ?? '—' }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Metodo
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                {{ $selectedPythonBestMatch['method'] ?? '—' }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Confidence
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                {{ $selectedPythonBestMatch['confidence'] ?? '—' }}
                                            </dd>
                                        </div>
                                    </dl>

                                    @if (($selectedPythonBestMatch['similarity'] ?? 0) < 70)
                                        <div class="mt-4 rounded-md bg-yellow-50 p-3">
                                            <p class="text-sm text-yellow-800">
                                                Match mostrato solo come dato diagnostico: la similarità è sotto la soglia di affidabilità.
                                            </p>
                                        </div>
                                    @endif
                                @else
                                    <div class="mt-4 rounded-md bg-gray-50 p-3">
                                        <p class="text-sm text-gray-700">
                                            Nessun risultato Python disponibile.
                                        </p>
                                    </div>
                                @endif
                            </section>

                            @if ($selectedIdentityGuardrails)
                                <section class="rounded-lg border border-gray-200 p-4">
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        Guardrail identità
                                    </h3>

                                    <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Token modello candidato
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                {{ implode(', ', $this->metadataList($selectedIdentityGuardrails['candidate_model_tokens'] ?? [])) ?: '—' }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Token modello canonico
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                {{ implode(', ', $this->metadataList($selectedIdentityGuardrails['canonical_model_tokens'] ?? [])) ?: '—' }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Conflitto modello
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                {{ ($selectedIdentityGuardrails['model_conflict'] ?? false) ? 'Sì' : 'No' }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Differenza specifiche
                                            </dt>
                                            <dd class="mt-1 text-sm text-gray-900">
                                                {{ ($selectedIdentityGuardrails['spec_difference'] ?? false) ? 'Sì' : 'No' }}
                                            </dd>
                                        </div>
                                    </dl>
                                </section>
                            @endif

                            <section class="rounded-lg border border-gray-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-sm font-semibold text-gray-900">
                                            Indicazioni di revisione
                                        </h3>

                                        <p class="mt-1 text-xs text-gray-600">
                                            Spiegazioni dei controlli effettuati sui dati del candidato.
                                        </p>
                                    </div>

                                    @if (
                                        (int) data_get(
                                            $selectedReviewSignalPresentation,
                                            'counts.primary',
                                            0
                                        ) > 0
                                    )
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">
                                            {{
                                                data_get(
                                                    $selectedReviewSignalPresentation,
                                                    'counts.primary'
                                                )
                                            }}
                                        </span>
                                    @endif
                                </div>

                                <div class="mt-4 space-y-3">
                                    @php
                                        $selectedHasPrimaryReviewSignals = false;
                                    @endphp

                                    @foreach (
                                        $selectedReviewSignalSections
                                        as $group => $section
                                    )
                                        @php
                                            $selectedGroupItems =
                                                $selectedReviewSignalGroups[$group] ?? [];

                                            $selectedGroupItems = is_array(
                                                $selectedGroupItems
                                            )
                                                ? $selectedGroupItems
                                                : [];
                                        @endphp

                                        @if ($selectedGroupItems !== [])
                                            @php
                                                $selectedHasPrimaryReviewSignals = true;
                                            @endphp

                                            <section
                                                class="rounded-md border p-4 {{ $section['container_classes'] }}"
                                            >
                                                <div class="flex items-center justify-between gap-3">
                                                    <div
                                                        class="text-xs font-medium uppercase tracking-wider {{ $section['title_classes'] }}"
                                                    >
                                                        {{ $section['title'] }}
                                                    </div>

                                                    @if (count($selectedGroupItems) > 1)
                                                        <span
                                                            class="text-xs font-medium {{ $section['message_classes'] }}"
                                                        >
                                                            {{ count($selectedGroupItems) }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="mt-3 space-y-4">
                                                    @foreach ($selectedGroupItems as $item)
                                                        <div>
                                                            <div
                                                                class="text-sm font-medium {{ $section['title_classes'] }}"
                                                            >
                                                                {{ $item['title'] }}
                                                            </div>

                                                            <p
                                                                class="mt-1 text-sm leading-6 {{ $section['message_classes'] }}"
                                                            >
                                                                {{ $item['message'] }}
                                                            </p>

                                                            @if ($item['action'] ?? null)
                                                                <p
                                                                    class="mt-1 text-sm font-medium leading-6 {{ $section['message_classes'] }}"
                                                                >
                                                                    {{ $item['action'] }}
                                                                </p>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </section>
                                        @endif
                                    @endforeach

                                    @unless ($selectedHasPrimaryReviewSignals)
                                        <div class="rounded-md bg-gray-50 p-3">
                                            <p class="text-sm text-gray-600">
                                                Nessuna verifica aggiuntiva richiesta.
                                            </p>
                                        </div>
                                    @endunless
                                </div>
                            </section>

                            <section class="rounded-lg border border-gray-200 p-4">
                                <details>
                                    <summary class="cursor-pointer text-sm font-semibold text-gray-900">
                                        Dettagli tecnici dei segnali
                                    </summary>

                                    <p class="mt-2 text-xs text-gray-600">
                                        Codici originali, sorgenti e classificazioni conservati
                                        per diagnostica.
                                    </p>

                                    @if ($selectedReviewSignalDiagnostics !== [])
                                        <div class="mt-4 space-y-3">
                                            @foreach (
                                                $selectedReviewSignalDiagnostics
                                                as $diagnosticItem
                                            )
                                                <div class="rounded-md bg-gray-50 p-3">
                                                    <div class="font-mono text-xs text-gray-900">
                                                        {{
                                                            $diagnosticItem['technical_code']
                                                                ?? 'unknown'
                                                        }}
                                                    </div>

                                                    <dl class="mt-2 grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                                        <div>
                                                            <dt class="text-gray-500">
                                                                Valore originale
                                                            </dt>

                                                            <dd class="mt-0.5 break-words text-gray-800">
                                                                {{
                                                                    $diagnosticItem['raw_value']
                                                                        ?? '—'
                                                                }}
                                                            </dd>
                                                        </div>

                                                        <div>
                                                            <dt class="text-gray-500">
                                                                Sorgente
                                                            </dt>

                                                            <dd class="mt-0.5 text-gray-800">
                                                                {{
                                                                    $diagnosticItem['source']
                                                                        ?? '—'
                                                                }}
                                                            </dd>
                                                        </div>

                                                        <div>
                                                            <dt class="text-gray-500">
                                                                Tipo
                                                            </dt>

                                                            <dd class="mt-0.5 text-gray-800">
                                                                {{
                                                                    $diagnosticItem['kind']
                                                                        ?? '—'
                                                                }}
                                                            </dd>
                                                        </div>

                                                        <div>
                                                            <dt class="text-gray-500">
                                                                Gruppo
                                                            </dt>

                                                            <dd class="mt-0.5 text-gray-800">
                                                                {{
                                                                    $diagnosticItem['group']
                                                                        ?? '—'
                                                                }}
                                                            </dd>
                                                        </div>

                                                        <div>
                                                            <dt class="text-gray-500">
                                                                Gravità
                                                            </dt>

                                                            <dd class="mt-0.5 text-gray-800">
                                                                {{
                                                                    $diagnosticItem['severity']
                                                                        ?? '—'
                                                                }}
                                                            </dd>
                                                        </div>

                                                        <div>
                                                            <dt class="text-gray-500">
                                                                Chiave deduplica
                                                            </dt>

                                                            <dd class="mt-0.5 break-words font-mono text-gray-800">
                                                                {{
                                                                    $diagnosticItem[
                                                                        'deduplication_key'
                                                                    ] ?? '—'
                                                                }}
                                                            </dd>
                                                        </div>
                                                    </dl>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <p class="mt-4 text-sm text-gray-500">
                                            Nessun segnale tecnico registrato.
                                        </p>
                                    @endif

                                    @if (
                                        $selectedSuppressedReviewSignalDuplicates !== []
                                    )
                                        <div class="mt-5 border-t border-gray-200 pt-4">
                                            <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Duplicati semantici nascosti nella UI
                                            </h4>

                                            <div class="mt-3 space-y-2">
                                                @foreach (
                                                    $selectedSuppressedReviewSignalDuplicates
                                                    as $duplicate
                                                )
                                                    <div class="rounded-md border border-gray-200 p-3 text-xs text-gray-700">
                                                        <div>
                                                            Chiave:
                                                            <span class="font-mono">
                                                                {{
                                                                    $duplicate[
                                                                        'deduplication_key'
                                                                    ] ?? '—'
                                                                }}
                                                            </span>
                                                        </div>

                                                        <div class="mt-1">
                                                            Mostrato:
                                                            <span class="font-mono">
                                                                {{
                                                                    data_get(
                                                                        $duplicate,
                                                                        'kept.technical_code',
                                                                        '—'
                                                                    )
                                                                }}
                                                            </span>
                                                        </div>

                                                        <div class="mt-1">
                                                            Nascosto:
                                                            <span class="font-mono">
                                                                {{
                                                                    data_get(
                                                                        $duplicate,
                                                                        'suppressed.technical_code',
                                                                        '—'
                                                                    )
                                                                }}
                                                            </span>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </details>
                            </section>

                            <section class="rounded-lg border border-gray-200 p-4">
                                <details>
                                    <summary class="cursor-pointer text-sm font-semibold text-gray-900">
                                        Metadata tecnici
                                    </summary>

                                    <pre class="mt-4 max-h-96 overflow-auto rounded-md bg-gray-950 p-4 text-xs text-gray-100">{{ json_encode($selectedCandidate->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            </section>
                        </div>

                        <div class="border-t border-gray-200 px-6 py-4">
                            <div class="flex flex-wrap justify-end gap-3">
                                <button
                                    type="button"
                                    wire:click="closeCandidateKnowledgeDrawer"
                                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                >
                                    Chiudi
                                </button>

                                @if ($selectedCandidate->document)
                                    <a
                                        href="{{ route('documents.show', $selectedCandidate->document) }}"
                                        class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                                    >
                                        Apri revisione documento
                                    </a>
                                @endif

                                @if ($selectedCandidate->product)
                                    <a
                                        href="{{ route('products.show', $selectedCandidate->product) }}"
                                        class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                    >
                                        Apri prodotto
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>