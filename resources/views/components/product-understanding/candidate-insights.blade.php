@props([
    'candidateMetadata' => [],
    'candidateId' => null,
    'canApplyCanonicalName' => false,
    'compact' => false,
])

@php
    $analysis = is_array($candidateMetadata['product_understanding'] ?? null)
        ? $candidateMetadata['product_understanding']
        : [];

    $feedback = is_array($candidateMetadata['product_understanding_feedback'] ?? null)
        ? $candidateMetadata['product_understanding_feedback']
        : [];

    $globalFact = is_array($candidateMetadata['product_understanding_global_fact'] ?? null)
        ? $candidateMetadata['product_understanding_global_fact']
        : [];

    $hasAnalysis = ! empty($analysis);
    $hasFeedback = ! empty($feedback);
    $hasGlobalFact = ($globalFact['matched'] ?? false) === true;

    $localBias = $feedback['suggested_bias'] ?? null;
    $globalBias = $globalFact['suggested_bias'] ?? null;

    $canonicalName = $globalFact['canonical_name'] ?? null;
    $candidateName = $globalFact['candidate_name'] ?? null;

    $candidateNameDiffersFromCanonical = (bool) ($globalFact['candidate_name_differs_from_canonical'] ?? false);

    $canShowApplyCanonicalNameButton =
        ! $compact
        && $canApplyCanonicalName
        && $candidateId
        && $hasGlobalFact
        && $candidateNameDiffersFromCanonical
        && filled($canonicalName);

    $localBadge = match ($localBias) {
        'previously_confirmed' => [
            'label' => 'Confermato nel workspace',
            'class' => 'bg-green-50 text-green-700 ring-green-600/20',
        ],
        'previously_ignored' => [
            'label' => 'Escluso nel workspace',
            'class' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
        ],
        'previously_seen' => [
            'label' => 'Già visto',
            'class' => 'bg-gray-50 text-gray-700 ring-gray-500/20',
        ],
        default => null,
    };

    $globalBadge = null;

    if ($hasGlobalFact) {
        if ($candidateNameDiffersFromCanonical) {
            $globalBadge = [
                'label' => 'Nome suggerito',
                'class' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
            ];
        } elseif (($globalFact['confirmed_count'] ?? 0) > 0) {
            $globalBadge = [
                'label' => 'EAN globale',
                'class' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            ];
        }
    }

    $reviewHint = $feedback['review_hint'] ?? $globalFact['review_hint'] ?? null;

    $reviewHintLabel = match ($reviewHint) {
        'same_ean_previously_confirmed' => 'Stesso EAN già confermato nel workspace.',
        'same_ean_previously_ignored' => 'Stesso EAN già escluso nel workspace.',
        'same_description_previously_confirmed' => 'Descrizione già confermata nel workspace.',
        'same_description_previously_ignored' => 'Descrizione già esclusa nel workspace.',
        'similar_description_previously_confirmed' => 'Descrizione simile a elementi già confermati.',
        'similar_description_previously_ignored' => 'Descrizione simile a elementi già esclusi.',
        'global_canonical_name_available' => 'È disponibile un nome globale più affidabile.',
        'ean_globally_confirmed' => 'EAN confermato nella conoscenza globale.',
        'ean_globally_seen_but_often_ignored' => 'EAN visto globalmente, ma spesso non registrato.',
        'ean_globally_mixed_registration_preference' => 'EAN con conferme ed esclusioni globali.',
        'ean_globally_seen' => 'EAN già visto nella conoscenza globale.',
        default => null,
    };
@endphp

@if ($compact)
    @if ($localBadge || $globalBadge)
        <div class="mt-2 flex flex-wrap gap-1.5">
            @if ($localBadge)
                <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset {{ $localBadge['class'] }}">
                    {{ $localBadge['label'] }}
                </span>
            @endif

            @if ($globalBadge)
                <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset {{ $globalBadge['class'] }}">
                    {{ $globalBadge['label'] }}
                </span>
            @endif
        </div>
    @endif
@else
    <div class="space-y-4">
        @if (! $hasAnalysis && ! $hasFeedback && ! $hasGlobalFact)
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <p class="text-sm text-gray-500">
                    Nessun dato di riconoscimento disponibile per questo candidato.
                </p>
            </div>
        @endif

        @if ($hasGlobalFact)
            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">
                            Conoscenza globale
                        </h3>

                        <p class="mt-1 text-xs text-gray-500">
                            Suggerimenti aggregati da EAN e feedback globali.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        @if ($globalBadge)
                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $globalBadge['class'] }}">
                                {{ $globalBadge['label'] }}
                            </span>
                        @endif

                        @if (($globalFact['global_product_confidence_score'] ?? null) !== null)
                            <span class="inline-flex items-center rounded-md bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-gray-700 ring-1 ring-inset ring-gray-500/20">
                                {{ $globalFact['global_product_confidence_score'] }}/100
                            </span>
                        @endif
                    </div>
                </div>

                @if ($candidateNameDiffersFromCanonical)
                    <div class="mt-4 rounded-md border border-indigo-200 bg-indigo-50 p-3">
                        <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wider text-indigo-700">
                                    Nome letto
                                </p>

                                <p class="mt-1 text-indigo-950">
                                    {{ $candidateName ?? '—' }}
                                </p>
                            </div>

                            <div>
                                <p class="text-xs font-medium uppercase tracking-wider text-indigo-700">
                                    Suggerimento globale
                                </p>

                                <p class="mt-1 font-medium text-indigo-950">
                                    {{ $canonicalName ?? '—' }}
                                </p>
                            </div>
                        </div>

                        @if ($canShowApplyCanonicalNameButton)
                            <div class="mt-4">
                                <button
                                    type="button"
                                    wire:click="applyGlobalCanonicalNameToCandidate({{ $candidateId }})"
                                    wire:loading.attr="disabled"
                                    wire:target="applyGlobalCanonicalNameToCandidate({{ $candidateId }})"
                                    class="inline-flex items-center rounded-md border border-indigo-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-indigo-700 shadow-sm hover:bg-indigo-50 disabled:opacity-50"
                                >
                                    Usa nome globale
                                </button>
                            </div>
                        @endif
                    </div>
                @elseif ($canonicalName)
                    <div class="mt-4 text-sm">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Nome canonico globale
                        </p>

                        <p class="mt-1 text-gray-900">
                            {{ $canonicalName }}
                        </p>
                    </div>
                @endif

                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Categoria
                        </dt>
                        <dd class="mt-1 text-gray-900">
                            {{ isset($globalFact['suggested_category']) ? str_replace('_', ' ', $globalFact['suggested_category']) : '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Tipo
                        </dt>
                        <dd class="mt-1 text-gray-900">
                            {{ isset($globalFact['suggested_line_type']) ? str_replace('_', ' ', $globalFact['suggested_line_type']) : '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Osservazioni
                        </dt>
                        <dd class="mt-1 text-gray-900">
                            {{ $globalFact['seen_count'] ?? 0 }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Confermati / esclusi
                        </dt>
                        <dd class="mt-1 text-gray-900">
                            {{ $globalFact['confirmed_count'] ?? 0 }}
                            /
                            {{ $globalFact['ignored_count'] ?? 0 }}
                        </dd>
                    </div>
                </dl>

                @if ($reviewHintLabel)
                    <p class="mt-4 rounded-md bg-gray-50 p-3 text-sm text-gray-700">
                        {{ $reviewHintLabel }}
                    </p>
                @endif
            </section>
        @endif

        @if ($hasFeedback)
            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">
                            Storico workspace
                        </h3>

                        <p class="mt-1 text-xs text-gray-500">
                            Feedback locale del workspace attivo.
                        </p>
                    </div>

                    @if ($localBadge)
                        <span class="inline-flex w-fit items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $localBadge['class'] }}">
                            {{ $localBadge['label'] }}
                        </span>
                    @endif
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Identità
                        </dt>
                        <dd class="mt-1 text-gray-900">
                            {{ $feedback['product_identity_score'] ?? 0 }}/100
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Preferenza
                        </dt>
                        <dd class="mt-1 text-gray-900">
                            {{ $feedback['registration_preference_score'] ?? 0 }}
                        </dd>
                    </div>
                </dl>
            </section>
        @endif

        @if ($hasAnalysis)
            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="text-sm font-semibold text-gray-900">
                    Analisi riga
                </h3>

                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Tipo
                        </dt>
                        <dd class="mt-1 text-gray-900">
                            {{ isset($analysis['line_type']) ? str_replace('_', ' ', $analysis['line_type']) : '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Categoria
                        </dt>
                        <dd class="mt-1 text-gray-900">
                            {{ isset($analysis['suggested_category']) ? str_replace('_', ' ', $analysis['suggested_category']) : '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Registrabile
                        </dt>
                        <dd class="mt-1 text-gray-900">
                            {{ $analysis['registerable_score'] ?? 0 }}/100
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Non prodotto
                        </dt>
                        <dd class="mt-1 text-gray-900">
                            {{ $analysis['non_product_score'] ?? 0 }}/100
                        </dd>
                    </div>
                </dl>
            </section>
        @endif
    </div>
@endif