{{-- resources/views/livewire/reviews/review-index.blade.php --}}

<div class="py-8">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">
                    Revisioni
                </h1>

                <p class="mt-2 max-w-3xl text-sm text-gray-600">
                    Controlla i documenti e i candidati prodotto che richiedono attenzione.
                    Qui trovi anche i segnali usati dal sistema per spiegare perché un prodotto è stato proposto.
                </p>
            </div>
        </div>

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
                            <option value="low_confidence">Bassa affidabilità</option>
                            <option value="python_warnings">Warning Python</option>
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

                            $pythonWarnings = $python['warnings'] ?? [];
                            $pythonSignals = $python['signals'] ?? [];
                            $globalSignals = $globalFact['signals'] ?? [];
                            $feedbackSignals = $feedback['signals'] ?? [];
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

                                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                                        <div class="rounded-md border border-gray-200 p-4">
                                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Identificazione
                                            </div>

                                            <dl class="mt-3 space-y-2 text-sm">
                                                <div>
                                                    <dt class="text-xs text-gray-500">Modello</dt>
                                                    <dd class="text-gray-900">{{ $candidate->model ?? '—' }}</dd>
                                                </div>

                                                <div>
                                                    <dt class="text-xs text-gray-500">EAN</dt>
                                                    <dd class="text-gray-900">{{ $candidate->ean_code ?? '—' }}</dd>
                                                </div>

                                                <div>
                                                    <dt class="text-xs text-gray-500">Seriale</dt>
                                                    <dd class="text-gray-900">{{ $candidate->serial_number ?? '—' }}</dd>
                                                </div>

                                                <div>
                                                    <dt class="text-xs text-gray-500">Categoria suggerita</dt>
                                                    <dd class="text-gray-900">{{ $understanding['suggested_category'] ?? '—' }}</dd>
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
                                                        @if (($globalFact['matched'] ?? false) === true)
                                                            {{ $globalFact['canonical_name'] ?? 'Match globale' }}
                                                        @else
                                                            Nessun match
                                                        @endif
                                                    </dd>
                                                </div>

                                                <div>
                                                    <dt class="text-xs text-gray-500">Python best match</dt>
                                                    <dd class="text-gray-900">
                                                        {{ $python['best_match']['canonical_name'] ?? '—' }}

                                                        @if ($python['best_match']['similarity'] ?? null)
                                                            <span class="block text-xs text-gray-500">
                                                                Similarità {{ $python['best_match']['similarity'] }}
                                                            </span>
                                                        @endif
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>

                                        <div class="rounded-md border border-gray-200 p-4">
                                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Segnali
                                            </div>

                                            <div class="mt-3 space-y-3">
                                                @if ($pythonWarnings !== [])
                                                    <div>
                                                        <div class="text-xs font-medium text-red-700">
                                                            Warning
                                                        </div>

                                                        <div class="mt-1 flex flex-wrap gap-1">
                                                            @foreach ($pythonWarnings as $warning)
                                                                <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs text-red-700">
                                                                    {{ $this->formatSignal($warning) }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                @php
                                                    $allSignals = collect($pythonSignals)
                                                        ->merge($globalSignals)
                                                        ->merge($feedbackSignals)
                                                        ->unique()
                                                        ->take(6);
                                                @endphp

                                                @if ($allSignals->isNotEmpty())
                                                    <div>
                                                        <div class="text-xs font-medium text-gray-500">
                                                            Segnali principali
                                                        </div>

                                                        <div class="mt-1 flex flex-wrap gap-1">
                                                            @foreach ($allSignals as $signal)
                                                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                                                                    {{ $this->formatSignal($signal) }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @else
                                                    <p class="text-sm text-gray-500">
                                                        Nessun segnale specifico registrato.
                                                    </p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex shrink-0 flex-col gap-2 lg:w-36">
                                    @if ($candidate->document)
                                        <a
                                            href="{{ route('documents.show', $candidate->document) }}"
                                            class="inline-flex items-center justify-center rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800"
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
</div>