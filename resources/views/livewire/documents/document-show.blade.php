{{-- resources/views/livewire/documents/document-show.blade.php --}}

@php
    $media = $document->getFirstMedia('original_file');
    $matchedSignals = $selectedClassification->metadata['matched_signals'] ?? [];
@endphp

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        {{-- Header --}}
        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <a
                    href="{{ route('documents.index') }}"
                    class="text-sm text-gray-600 hover:text-gray-900"
                >
                    ← Torna ai documenti
                </a>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold text-gray-900">
                        {{ $document->original_filename ?? 'Documento #' . $document->id }}
                    </h1>

                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->statusBadgeClasses }}">
                        {{ $this->statusLabel }}
                    </span>
                </div>

                <p class="mt-2 text-sm text-gray-600">
                    Documento caricato il {{ $document->created_at?->format('d/m/Y H:i') }}
                    da {{ $document->uploadedBy?->name ?? '—' }}.
                </p>
            </div>
        </div>

        {{-- Riepilogo compatto --}}
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Tipo
                </dt>
                <dd class="mt-1 text-sm font-medium text-gray-900">
                    {{ $document->documentType?->name ?? 'Non classificato' }}
                </dd>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Venditore
                </dt>
                <dd class="mt-1 truncate text-sm font-medium text-gray-900">
                    {{ $document->merchant?->name ?? '—' }}
                </dd>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Data rilevata
                </dt>
                <dd class="mt-1 text-sm font-medium text-gray-900">
                    {{ $document->purchase_date?->format('d/m/Y') ?? '—' }}
                </dd>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Totale
                </dt>
                <dd class="mt-1 text-sm font-medium text-gray-900">
                    @if ($document->total_amount)
                        {{ number_format($document->total_amount, 2, ',', '.') }}
                        {{ $document->currency?->code }}
                    @else
                        —
                    @endif
                </dd>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Estrazione
                </dt>
                <dd class="mt-2">
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->textExtractionStatusBadgeClasses }}">
                        {{ $this->textExtractionStatusLabel }}
                    </span>
                </dd>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            {{-- Colonna principale --}}
            <div class="space-y-6 xl:col-span-2">
                {{-- Dati estratti --}}
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-lg font-medium text-gray-900">
                                    Dati estratti
                                </h2>

                                <p class="mt-1 text-sm text-gray-600">
                                    Informazioni individuate automaticamente dal documento. Saranno modificabili nella fase di revisione.
                                </p>
                            </div>

                            @if (in_array($document->status, ['parsed', 'needs_review'], true))
                                <span class="inline-flex shrink-0 items-center rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                    Dati base estratti
                                </span>
                            @elseif ($document->status === 'classified')
                                <span class="inline-flex shrink-0 items-center rounded-full bg-yellow-50 px-2.5 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">
                                    In attesa di parsing
                                </span>
                            @elseif ($document->text_extraction_status === 'requires_ocr')
                                <span class="inline-flex shrink-0 items-center rounded-full bg-orange-50 px-2.5 py-1 text-xs font-medium text-orange-700 ring-1 ring-inset ring-orange-600/20">
                                    Richiede OCR
                                </span>
                            @else
                                <span class="inline-flex shrink-0 items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-500/20">
                                    Non disponibile
                                </span>
                            @endif
                        </div>

                        @if (session()->has('review_success'))
                            <div class="mt-5 rounded-md bg-green-50 p-4 text-sm text-green-700">
                                {{ session('review_success') }}
                            </div>
                        @endif

                        @if (session()->has('review_warning'))
                            <div class="mt-5 rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                                {{ session('review_warning') }}
                            </div>
                        @endif

                        <form wire:submit.prevent="saveDocumentReviewData" class="mt-6 space-y-5">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div class="sm:col-span-3">
                                    <label for="editMerchantName" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Venditore
                                    </label>

                                    <input
                                        id="editMerchantName"
                                        type="text"
                                        wire:model.defer="editMerchantName"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        placeholder="Nome venditore"
                                    >

                                    @error('editMerchantName')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="editPurchaseDate" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Data documento
                                    </label>

                                    <input
                                        id="editPurchaseDate"
                                        type="date"
                                        wire:model.defer="editPurchaseDate"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >

                                    @error('editPurchaseDate')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="editTotalAmount" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Totale
                                    </label>

                                    <input
                                        id="editTotalAmount"
                                        type="text"
                                        inputmode="decimal"
                                        wire:model.defer="editTotalAmount"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        placeholder="0,00"
                                    >

                                    @error('editTotalAmount')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Valuta
                                    </label>

                                    <div class="mt-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                                        {{ $document->currency?->code ?? '—' }}
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-3 rounded-md border border-blue-200 bg-blue-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm text-blue-700">
                                    Correggi qui i dati base prima di confermare un prodotto. Le modifiche non rilanciano automaticamente OCR o parsing.
                                </p>

                                <div class="flex shrink-0 gap-2">
                                    <button
                                        type="button"
                                        wire:click="resetDocumentReviewForm"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
                                    >
                                        Annulla
                                    </button>

                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="saveDocumentReviewData"
                                        class="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 disabled:opacity-50"
                                    >
                                        Salva dati
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>

{{-- Righe prodotto candidate --}}
@php
    $documentLines = $document->lines->sortBy('line_number');
    $productCandidatesByLineId = $document->productIdentificationCandidates->keyBy('document_line_id');
    $lineDiagnostics = $this->lineExtractionDiagnostics;
@endphp

<details class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg" open>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-6">
        <div>
            <h2 class="text-lg font-medium text-gray-900">
                Righe prodotto candidate
            </h2>

            <p class="mt-1 text-sm text-gray-600">
                @if ($documentLines->count() > 0)
                    {{ $documentLines->count() }}
                    {{ $documentLines->count() === 1 ? 'riga individuata' : 'righe individuate' }}

                    @if ($productCandidatesByLineId->count() > 0)
                        · {{ $productCandidatesByLineId->count() }}
                        {{ $productCandidatesByLineId->count() === 1 ? 'candidato prodotto' : 'candidati prodotto' }}
                    @endif
                @else
                    Nessuna riga prodotto individuata
                @endif
            </p>
        </div>

        <span class="text-sm text-gray-500">
            Apri
        </span>
    </summary>

    <div class="border-t border-gray-200 p-6">
        @if (session()->has('product_success'))
            <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-700">
                {{ session('product_success') }}
            </div>
        @endif

        @if (session()->has('product_warning'))
            <div class="mb-4 rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                {{ session('product_warning') }}
            </div>
        @endif

        @if (session()->has('line_success'))
            <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-700">
                {{ session('line_success') }}
            </div>
        @endif

        @if (session()->has('line_warning'))
            <div class="mb-4 rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                {{ session('line_warning') }}
            </div>
        @endif

        @if (session()->has('candidate_success'))
            <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-700">
                {{ session('candidate_success') }}
            </div>
        @endif

        @if (session()->has('candidate_warning'))
            <div class="mb-4 rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                {{ session('candidate_warning') }}
            </div>
        @endif

        @if ($documentLines->isNotEmpty())
            <div class="mb-5 flex flex-col gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">
                        Diagnostica estrazione
                    </h3>

                    <p class="mt-1 text-xs text-gray-600">
                        Riepilogo tecnico compatto. I dettagli sono nel pannello laterale.
                    </p>
                </div>

                <div class="flex flex-wrap justify-center gap-2 sm:justify-end">
                    <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200">
                        Righe: {{ $lineDiagnostics['lines_count'] }}
                    </span>

                    <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200">
                        Candidati: {{ $lineDiagnostics['candidates_count'] }}
                    </span>

                    @if ($lineDiagnostics['average_extraction_score'] !== null)
                        <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200">
                            Score medio: {{ $lineDiagnostics['average_extraction_score'] }}/100
                        </span>
                    @endif

                    <button
                        type="button"
                        x-data
                        x-on:click="$dispatch('open-drawer', { id: 'line-diagnostics-drawer' })"
                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50"
                    >
                        Dettagli diagnostica
                    </button>

                    @if ($documentLines->isNotEmpty() && $document->status !== 'linked_to_product')
                        <button
                            type="button"
                            wire:click="regenerateProductCandidates"
                            wire:loading.attr="disabled"
                            wire:target="regenerateProductCandidates"
                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
                        >
                            Rigenera candidati
                        </button>
                    @endif
                </div>
            </div>

            <x-ui.drawer
                id="line-diagnostics-drawer"
                title="Diagnostica estrazione righe"
                description="Informazioni tecniche utili per capire come sono state generate righe e candidati."
                width="max-w-3xl"
            >
                <dl class="grid grid-cols-1 gap-5 text-sm sm:grid-cols-2">
                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Parser
                        </dt>

                        <dd class="mt-3 space-y-1 text-gray-800">
                            @forelse ($lineDiagnostics['parser_counts'] as $parser => $count)
                                <div>{{ str_replace('_', ' ', $parser) }} · {{ $count }}</div>
                            @empty
                                <div>—</div>
                            @endforelse
                        </dd>
                    </div>

                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Mode
                        </dt>

                        <dd class="mt-3 space-y-1 text-gray-800">
                            @forelse ($lineDiagnostics['mode_counts'] as $mode => $count)
                                <div>{{ str_replace('_', ' ', $mode) }} · {{ $count }}</div>
                            @empty
                                <div>—</div>
                            @endforelse
                        </dd>
                    </div>

                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Strategie tabellari
                        </dt>

                        <dd class="mt-3 space-y-1 text-gray-800">
                            @forelse ($lineDiagnostics['strategy_counts'] as $strategy => $count)
                                <div>{{ str_replace('_', ' ', $strategy) }} · {{ $count }}</div>
                            @empty
                                <div>—</div>
                            @endforelse
                        </dd>
                    </div>

                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                            Supporto / job
                        </dt>

                        <dd class="mt-3 space-y-1 text-gray-800">
                            <div>Linee supporto: {{ $lineDiagnostics['supporting_lines_count'] }}</div>
                            <div>Righe con supporto: {{ $lineDiagnostics['lines_with_supporting_lines'] }}</div>

                            @if ($lineDiagnostics['line_parsing_lines_created'] !== null)
                                <div>Job righe create: {{ $lineDiagnostics['line_parsing_lines_created'] }}</div>
                            @endif

                            @if ($lineDiagnostics['candidate_generation_candidates_created'] !== null)
                                <div>Job candidati creati: {{ $lineDiagnostics['candidate_generation_candidates_created'] }}</div>
                            @endif
                        </dd>
                    </div>
                </dl>

                @if (! empty($lineDiagnostics['warnings']))
                    <div class="mt-5 rounded-md border border-yellow-200 bg-yellow-50 p-4">
                        <h4 class="text-xs font-medium uppercase tracking-wider text-yellow-800">
                            Warning estrazione
                        </h4>

                        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-yellow-800">
                            @foreach ($lineDiagnostics['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-ui.drawer>

            <div class="space-y-3">
                @foreach ($documentLines as $line)
                    @php
                        $productCode = $line->metadata['product_code_candidate'] ?? null;
                        $eanCodeCandidate = $line->metadata['ean_code_candidate'] ?? null;
                        $mode = $line->metadata['mode'] ?? null;
                        $parser = $line->metadata['parser'] ?? null;
                        $extractionStrategy = $line->metadata['extraction_strategy'] ?? null;
                        $extractionScore = $line->metadata['extraction_score'] ?? null;
                        $supportingLines = $line->metadata['supporting_lines'] ?? [];

                        $candidate = $productCandidatesByLineId->get($line->id);
                        $candidateMetadata = $candidate?->metadata ?? [];
                        $sourceLine = $candidate?->documentLine;
                        $sourceLineMetadata = $sourceLine?->metadata ?? [];
                        $candidateSupportingLines = $sourceLineMetadata['supporting_lines'] ?? [];

                        $previewName = $candidate?->name ?: ($line->description ?? 'Riga senza nome');
                    @endphp

                    <details
                        wire:key="document-line-card-{{ $line->id }}"
                        class="group rounded-xl border border-gray-200 bg-white shadow-sm"
                    >
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5">
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    {{ $candidate ? 'Candidato prodotto' : 'Riga estratta' }}
                                </p>

                                <h3 class="mt-1 truncate text-sm font-semibold text-gray-900">
                                    {{ $previewName }}
                                </h3>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                @if ($candidate?->confidence_score !== null)
                                    <span class="hidden items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset sm:inline-flex
                                        @if ($candidate->confidence_score >= 80)
                                            bg-green-50 text-green-700 ring-green-600/20
                                        @elseif ($candidate->confidence_score >= 50)
                                            bg-yellow-50 text-yellow-800 ring-yellow-600/20
                                        @else
                                            bg-red-50 text-red-700 ring-red-600/20
                                        @endif
                                    ">
                                        {{ $candidate->confidence_score }}/100
                                    </span>
                                @elseif ($line->confidence_score !== null)
                                    <span class="hidden items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset sm:inline-flex
                                        @if ($line->confidence_score >= 80)
                                            bg-green-50 text-green-700 ring-green-600/20
                                        @elseif ($line->confidence_score >= 50)
                                            bg-yellow-50 text-yellow-800 ring-yellow-600/20
                                        @else
                                            bg-red-50 text-red-700 ring-red-600/20
                                        @endif
                                    ">
                                        {{ $line->confidence_score }}/100
                                    </span>
                                @endif

                                <span class="text-sm text-gray-400 group-open:hidden">
                                    Apri
                                </span>

                                <span class="hidden text-sm text-gray-400 group-open:inline">
                                    Chiudi
                                </span>
                            </div>
                        </summary>

                        <div class="border-t border-gray-200 p-5">
                            <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
                                <div class="xl:col-span-4">
                                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Riga estratta
                                    </p>

                                    <h4 class="mt-2 text-sm font-semibold text-gray-900">
                                        {{ $line->description ?? '—' }}
                                    </h4>

                                    <dl class="mt-4 grid grid-cols-3 gap-3 text-xs">
                                        <div>
                                            <dt class="text-gray-500">Quantità</dt>
                                            <dd class="font-medium text-gray-800">
                                                {{ $line->quantity !== null ? number_format((float) $line->quantity, 3, ',', '.') : '—' }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-gray-500">Unitario</dt>
                                            <dd class="font-medium text-gray-800">
                                                {{ $line->unit_price !== null ? number_format((float) $line->unit_price, 2, ',', '.') : '—' }}
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-gray-500">Totale</dt>
                                            <dd class="font-medium text-gray-800">
                                                {{ $line->total_price !== null ? number_format((float) $line->total_price, 2, ',', '.') : '—' }}
                                            </dd>
                                        </div>
                                    </dl>

                                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                                        <button
                                            type="button"
                                            x-data
                                            x-on:click="$dispatch('open-drawer', { id: 'line-details-{{ $line->id }}' })"
                                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50"
                                        >
                                            Dettagli riga
                                        </button>

                                        @if ($document->status !== 'linked_to_product')
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="$dispatch('open-drawer', { id: 'line-edit-{{ $line->id }}' })"
                                                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50"
                                            >
                                                Modifica riga
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                <div class="xl:col-span-5">
                                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Candidato prodotto
                                    </p>

                                    @if ($candidate)
                                        <h4 class="mt-2 text-sm font-semibold text-gray-900">
                                            {{ $candidate->name ?? '—' }}
                                        </h4>

                                        <div class="mt-2 space-y-1 text-xs text-gray-600">
                                            @if ($candidate->model)
                                                <div>Modello: {{ $candidate->model }}</div>
                                            @endif

                                            @if ($candidate->ean_code)
                                                <div>EAN: {{ $candidate->ean_code }}</div>
                                            @endif

                                            @if ($candidate->serial_number)
                                                <div>Seriale: {{ $candidate->serial_number }}</div>
                                            @endif

                                            @if ($candidate->price !== null)
                                                <div>
                                                    Prezzo candidato:
                                                    {{ number_format((float) $candidate->price, 2, ',', '.') }}
                                                    {{ $document->currency?->code }}
                                                </div>
                                            @endif

                                            <div class="text-gray-400">
                                                Fonte: {{ str_replace('_', ' ', $candidate->source) }}
                                            </div>
                                        </div>

                                        <div class="mt-5 flex flex-wrap justify-center gap-2">
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="$dispatch('open-drawer', { id: 'candidate-source-{{ $candidate->id }}' })"
                                                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50"
                                            >
                                                Sorgente candidato
                                            </button>

                                            @if (! $candidate->product_id && $document->status !== 'linked_to_product')
                                                <button
                                                    type="button"
                                                    x-data
                                                    x-on:click="$dispatch('open-drawer', { id: 'candidate-edit-{{ $candidate->id }}' })"
                                                    class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50"
                                                >
                                                    Modifica candidato
                                                </button>

                                                <button
                                                    type="button"
                                                    wire:click="deleteProductCandidate({{ $candidate->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="deleteProductCandidate({{ $candidate->id }})"
                                                    class="inline-flex items-center rounded-md border border-red-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-red-700 shadow-sm hover:bg-red-50 disabled:opacity-50"
                                                >
                                                    Elimina candidato
                                                </button>
                                            @endif
                                        </div>
                                    @else
                                        <div class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
                                            Nessun candidato generato da questa riga.
                                        </div>
                                    @endif
                                </div>

                                <div class="flex flex-col items-center justify-center text-center xl:col-span-3">
                                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Azione principale
                                    </p>

                                    <div class="mt-4 w-full max-w-xs space-y-3">
                                        @if ($candidate)
                                            @if ($candidate->product_id && $candidate->product)
                                                <span class="inline-flex items-center rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                                    Prodotto creato
                                                </span>

                                                <a
                                                    href="{{ route('products.show', $candidate->product) }}"
                                                    class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50"
                                                >
                                                    Apri prodotto
                                                </a>
                                            @elseif ($document->status === 'linked_to_product')
                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-500/20">
                                                    Documento già collegato
                                                </span>
                                            @else
                                                <button
                                                    type="button"
                                                    wire:click="confirmProductCandidate({{ $candidate->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="confirmProductCandidate({{ $candidate->id }})"
                                                    class="inline-flex w-full items-center justify-center rounded-md border border-transparent bg-gray-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 disabled:opacity-50"
                                                >
                                                    Conferma e crea prodotto
                                                </button>
                                            @endif
                                        @else
                                            <span class="text-sm text-gray-500">
                                                Nessuna azione disponibile.
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </details>

                    <x-ui.drawer
                        id="line-details-{{ $line->id }}"
                        title="Dettagli riga estratta"
                        description="Dati tecnici e sorgente della riga individuata dal parser."
                        width="max-w-3xl"
                    >
                        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Descrizione
                                </dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $line->description ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Codice letto
                                </dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $productCode ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    EAN letto
                                </dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $eanCodeCandidate ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Parser / mode
                                </dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $parser ? str_replace('_', ' ', $parser) : '—' }}

                                    @if ($mode)
                                        · {{ str_replace('_', ' ', $mode) }}
                                    @endif
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Strategia
                                </dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $extractionStrategy ? str_replace('_', ' ', $extractionStrategy) : '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Score estrazione
                                </dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $extractionScore !== null ? $extractionScore . '/100' : '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Quantità
                                </dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $line->quantity !== null ? number_format((float) $line->quantity, 3, ',', '.') : '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Prezzo unitario
                                </dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $line->unit_price !== null ? number_format((float) $line->unit_price, 2, ',', '.') . ' ' . ($document->currency?->code ?? '') : '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Totale riga
                                </dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $line->total_price !== null ? number_format((float) $line->total_price, 2, ',', '.') . ' ' . ($document->currency?->code ?? '') : '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Affidabilità riga
                                </dt>
                                <dd class="mt-1 text-gray-900">
                                    {{ $line->confidence_score !== null ? $line->confidence_score . '/100' : '—' }}
                                </dd>
                            </div>

                            <div class="sm:col-span-2">
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Testo grezzo riga
                                </dt>
                                <dd class="mt-2 whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 p-3 text-gray-800">
                                    {{ $line->raw_text ?? '—' }}
                                </dd>
                            </div>

                            @if (! empty($supportingLines))
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Linee di supporto
                                    </dt>
                                    <dd class="mt-2">
                                        <ul class="list-disc space-y-1 pl-5 text-gray-700">
                                            @foreach ($supportingLines as $supportingLine)
                                                <li>{{ $supportingLine }}</li>
                                            @endforeach
                                        </ul>
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </x-ui.drawer>

                    @if ($candidate)
                        <x-ui.drawer
                            id="candidate-source-{{ $candidate->id }}"
                            title="Sorgente candidato prodotto"
                            description="Dati usati per generare il candidato prodotto."
                            width="max-w-3xl"
                        >
                            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nome candidato
                                    </dt>
                                    <dd class="mt-1 text-gray-900">
                                        {{ $candidate->name ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Riga sorgente
                                    </dt>
                                    <dd class="mt-1 text-gray-900">
                                        {{ $sourceLine?->description ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Quantità letta
                                    </dt>
                                    <dd class="mt-1 text-gray-900">
                                        {{ isset($candidateMetadata['quantity']) && $candidateMetadata['quantity'] !== null
                                            ? number_format((float) $candidateMetadata['quantity'], 3, ',', '.')
                                            : '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Prezzo unitario sorgente
                                    </dt>
                                    <dd class="mt-1 text-gray-900">
                                        {{ isset($candidateMetadata['unit_price']) && $candidateMetadata['unit_price'] !== null
                                            ? number_format((float) $candidateMetadata['unit_price'], 2, ',', '.') . ' ' . ($document->currency?->code ?? '')
                                            : '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Totale riga sorgente
                                    </dt>
                                    <dd class="mt-1 text-gray-900">
                                        {{ isset($candidateMetadata['total_price']) && $candidateMetadata['total_price'] !== null
                                            ? number_format((float) $candidateMetadata['total_price'], 2, ',', '.') . ' ' . ($document->currency?->code ?? '')
                                            : '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Codice candidato
                                    </dt>
                                    <dd class="mt-1 text-gray-900">
                                        {{ $candidateMetadata['product_code_candidate'] ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        EAN candidato
                                    </dt>
                                    <dd class="mt-1 text-gray-900">
                                        {{ $candidate->ean_code ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Seriale candidato
                                    </dt>
                                    <dd class="mt-1 text-gray-900">
                                        {{ $candidate->serial_number ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Parser / mode
                                    </dt>
                                    <dd class="mt-1 text-gray-900">
                                        {{ str_replace('_', ' ', $candidateMetadata['line_parser'] ?? '—') }}

                                        @if (! empty($candidateMetadata['line_mode']))
                                            · {{ str_replace('_', ' ', $candidateMetadata['line_mode']) }}
                                        @endif
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Affidabilità candidato
                                    </dt>
                                    <dd class="mt-1 text-gray-900">
                                        {{ $candidate->confidence_score !== null ? $candidate->confidence_score . '/100' : '—' }}
                                    </dd>
                                </div>

                                @if (! empty($candidateMetadata['raw_line_text']))
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Testo riga sorgente
                                        </dt>
                                        <dd class="mt-2 whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 p-3 text-gray-800">
                                            {{ $candidateMetadata['raw_line_text'] }}
                                        </dd>
                                    </div>
                                @endif

                                @if (! empty($candidateSupportingLines))
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Linee di supporto
                                        </dt>
                                        <dd class="mt-2">
                                            <ul class="list-disc space-y-1 pl-5 text-gray-700">
                                                @foreach ($candidateSupportingLines as $supportingLine)
                                                    <li>{{ $supportingLine }}</li>
                                                @endforeach
                                            </ul>
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </x-ui.drawer>

                        @if (! $candidate->product_id && $document->status !== 'linked_to_product')
                            <x-ui.drawer
                                id="candidate-edit-{{ $candidate->id }}"
                                title="Modifica candidato prodotto"
                                description="Correggi i dati prima di creare la scheda prodotto."
                                width="max-w-xl"
                            >
                                <form
                                    wire:key="candidate-review-form-{{ $candidate->id }}"
                                    wire:submit.prevent="saveProductCandidateReviewData({{ $candidate->id }})"
                                    class="space-y-4"
                                >
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">
                                            Nome prodotto
                                        </label>

                                        <input
                                            type="text"
                                            wire:model.defer="candidateReviewForms.{{ $candidate->id }}.name"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >

                                        @error("candidateReviewForms.{$candidate->id}.name")
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">
                                                Modello
                                            </label>

                                            <input
                                                type="text"
                                                wire:model.defer="candidateReviewForms.{{ $candidate->id }}.model"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">
                                                Prezzo
                                            </label>

                                            <input
                                                type="text"
                                                inputmode="decimal"
                                                wire:model.defer="candidateReviewForms.{{ $candidate->id }}.price"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >

                                            @error("candidateReviewForms.{$candidate->id}.price")
                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">
                                                Seriale
                                            </label>

                                            <input
                                                type="text"
                                                wire:model.defer="candidateReviewForms.{{ $candidate->id }}.serial_number"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">
                                                EAN
                                            </label>

                                            <input
                                                type="text"
                                                wire:model.defer="candidateReviewForms.{{ $candidate->id }}.ean_code"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                        </div>
                                    </div>

                                    <div class="flex justify-center border-t border-gray-200 pt-4">
                                        <button
                                            type="submit"
                                            wire:loading.attr="disabled"
                                            wire:target="saveProductCandidateReviewData({{ $candidate->id }})"
                                            class="inline-flex items-center justify-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 disabled:opacity-50"
                                        >
                                            Salva candidato
                                        </button>
                                    </div>
                                </form>
                            </x-ui.drawer>
                        @endif
                    @endif

                    @if ($document->status !== 'linked_to_product')
                        <x-ui.drawer
                            id="line-edit-{{ $line->id }}"
                            title="Modifica riga documento"
                            description="Correggi i dati della riga sorgente e poi rigenera i candidati."
                            width="max-w-xl"
                        >
                            <form
                                wire:submit.prevent="saveDocumentLineReviewData({{ $line->id }})"
                                class="space-y-4"
                            >
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">
                                        Descrizione
                                    </label>

                                    <input
                                        type="text"
                                        wire:model.defer="lineReviewForms.{{ $line->id }}.description"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >

                                    @error("lineReviewForms.{$line->id}.description")
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">
                                            Quantità
                                        </label>

                                        <input
                                            type="text"
                                            inputmode="decimal"
                                            wire:model.defer="lineReviewForms.{{ $line->id }}.quantity"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >

                                        @error("lineReviewForms.{$line->id}.quantity")
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">
                                            Prezzo unitario
                                        </label>

                                        <input
                                            type="text"
                                            inputmode="decimal"
                                            wire:model.defer="lineReviewForms.{{ $line->id }}.unit_price"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >

                                        @error("lineReviewForms.{$line->id}.unit_price")
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">
                                            Totale riga
                                        </label>

                                        <input
                                            type="text"
                                            inputmode="decimal"
                                            wire:model.defer="lineReviewForms.{{ $line->id }}.total_price"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >

                                        @error("lineReviewForms.{$line->id}.total_price")
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">
                                            Codice / modello candidato
                                        </label>

                                        <input
                                            type="text"
                                            wire:model.defer="lineReviewForms.{{ $line->id }}.product_code_candidate"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">
                                            Seriale candidato
                                        </label>

                                        <input
                                            type="text"
                                            wire:model.defer="lineReviewForms.{{ $line->id }}.serial_number_candidate"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                                    <button
                                        type="button"
                                        wire:click="deleteDocumentLine({{ $line->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="deleteDocumentLine({{ $line->id }})"
                                        class="inline-flex items-center justify-center rounded-md border border-red-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-red-700 shadow-sm hover:bg-red-50 disabled:opacity-50"
                                    >
                                        Elimina riga
                                    </button>

                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="saveDocumentLineReviewData({{ $line->id }})"
                                        class="inline-flex items-center justify-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 disabled:opacity-50"
                                    >
                                        Salva riga
                                    </button>
                                </div>
                            </form>
                        </x-ui.drawer>
                    @endif
                @endforeach
            </div>

            <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 p-4">
                <p class="text-sm text-blue-700">
                    Le righe e i candidati prodotto sono proposte automatiche. Nel flusso di revisione potranno essere confermati, corretti o esclusi prima di creare una scheda prodotto.
                </p>
            </div>
        @elseif ($document->text_extraction_status === 'requires_ocr')
            <div class="rounded-md border border-orange-200 bg-orange-50 p-4">
                <p class="text-sm text-orange-800">
                    Le righe prodotto potranno essere estratte dopo l’OCR, perché il documento non ha ancora testo estraibile.
                </p>
            </div>
        @else
            <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                <p class="text-sm text-gray-700">
                    Nessuna riga prodotto candidata è stata individuata in questo documento.
                </p>
            </div>
        @endif
    </div>
</details>

                {{-- Venditore --}}
                <details class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-6">
                        <div>
                            <h2 class="text-lg font-medium text-gray-900">
                                Venditore rilevato
                            </h2>

                            <p class="mt-1 text-sm text-gray-600">
                                {{ $document->merchant?->name ?? 'Nessun venditore rilevato' }}
                                @if ($document->merchant?->vat_number)
                                    · P.IVA {{ $document->merchant->vat_number }}
                                @endif
                            </p>
                        </div>

                        <span class="text-sm text-gray-500">
                            Apri
                        </span>
                    </summary>

                    <div class="border-t border-gray-200 p-6">
                        @if ($document->merchant)
                            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nome
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $document->merchant->name }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        P.IVA
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $document->merchant->vat_number ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Email
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if ($document->merchant->email)
                                            <a
                                                href="mailto:{{ $document->merchant->email }}"
                                                class="text-indigo-600 hover:text-indigo-800"
                                            >
                                                {{ $document->merchant->email }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Stato verifica
                                    </dt>
                                    <dd class="mt-2">
                                        @if ($document->merchant->is_verified)
                                            <span class="inline-flex items-center rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                                Verificato
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-yellow-50 px-2.5 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">
                                                Da verificare
                                            </span>
                                        @endif
                                    </dd>
                                </div>

                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Indirizzo
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $document->merchant->address ?? '—' }}
                                    </dd>
                                </div>
                            </dl>

                            <div class="mt-5 rounded-md border border-blue-200 bg-blue-50 p-4">
                                <p class="text-sm text-blue-700">
                                    Il venditore è stato associato automaticamente. Nella fase di revisione potrà essere confermato, corretto o sostituito.
                                </p>
                            </div>
                        @elseif ($document->text_extraction_status === 'requires_ocr')
                            <div class="rounded-md border border-orange-200 bg-orange-50 p-4">
                                <p class="text-sm text-orange-800">
                                    Il venditore potrà essere rilevato dopo l’OCR, perché il documento non ha ancora testo estraibile.
                                </p>
                            </div>
                        @else
                            <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                                <p class="text-sm text-gray-700">
                                    Nessun venditore rilevato per questo documento.
                                </p>
                            </div>
                        @endif
                    </div>
                </details>

                {{-- Classificazione documento --}}
                <details class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-6">
                        <div>
                            <h2 class="text-lg font-medium text-gray-900">
                                Classificazione documento
                            </h2>

                            <p class="mt-1 text-sm text-gray-600">
                                {{ $this->selectedClassificationTypeLabel }}

                                @if ($selectedClassification?->confidence_score !== null)
                                    · Confidenza {{ $selectedClassification->confidence_score }}/100
                                @endif
                            </p>
                        </div>

                        <span class="text-sm text-gray-500">
                            Apri
                        </span>
                    </summary>

                    <div class="border-t border-gray-200 p-6">
                        @if ($selectedClassification)
                            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tipo rilevato
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $this->selectedClassificationTypeLabel }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Confidenza
                                    </dt>
                                    <dd class="mt-2">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->selectedClassificationConfidenceBadgeClasses }}">
                                            {{ $selectedClassification->confidence_score }}/100
                                        </span>
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Motore
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $this->selectedClassificationEngineLabel }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Codice tipo
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $selectedClassification->documentType?->code ?? '—' }}
                                    </dd>
                                </div>

                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Motivo
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $selectedClassification->reason ?? '—' }}
                                    </dd>
                                </div>
                            </dl>

                            @if (! empty($matchedSignals))
                                <div class="mt-5">
                                    <h3 class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Segnali trovati
                                    </h3>

                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach ($matchedSignals as $signal)
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">
                                                {{ $signal }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @elseif ($document->text_extraction_status === 'requires_ocr')
                            <div class="rounded-md border border-orange-200 bg-orange-50 p-4">
                                <p class="text-sm text-orange-800">
                                    La classificazione automatica partirà dopo l’OCR, perché il documento non ha ancora testo estraibile.
                                </p>
                            </div>
                        @else
                            <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                                <p class="text-sm text-gray-700">
                                    Nessuna classificazione disponibile per questo documento.
                                </p>
                            </div>
                        @endif
                    </div>
                </details>

                {{-- Dettagli tecnici documento --}}
                <details class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <summary class="flex cursor-pointer list-none items-center justify-between p-6">
                        <div>
                            <h2 class="text-lg font-medium text-gray-900">
                                Dettagli tecnici documento
                            </h2>
                            <p class="mt-1 text-sm text-gray-600">
                                Metadati del record e informazioni tecniche non essenziali.
                            </p>
                        </div>

                        <span class="text-sm text-gray-500">
                            Apri
                        </span>
                    </summary>

                    <div class="border-t border-gray-200 p-6">
                        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Nome originale
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $document->original_filename ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    MIME type
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $document->mime_type ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Dimensione
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $this->formatBytes($document->file_size) }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Sorgente
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $document->source === 'manual_upload' ? 'Upload manuale' : ($document->source ?? '—') }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Caricato da
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $document->uploadedBy?->name ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Caricato il
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $document->created_at?->format('d/m/Y H:i') }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </details>

                {{-- Ultima estrazione testo --}}
                <details class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <summary class="flex cursor-pointer list-none items-center justify-between p-6">
                        <div>
                            <h2 class="text-lg font-medium text-gray-900">
                                Ultima estrazione testo
                            </h2>
                            <p class="mt-1 text-sm text-gray-600">
                                Stato: {{ $this->latestTextExtractionStatusLabel }} · Motore: {{ $this->latestTextExtractionEngineLabel }}
                            </p>
                        </div>

                        <span class="text-sm text-gray-500">
                            Apri
                        </span>
                    </summary>

                    <div class="border-t border-gray-200 p-6">
                        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Stato estrazione
                                </dt>
                                <dd class="mt-2">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->latestTextExtractionStatusBadgeClasses }}">
                                        {{ $this->latestTextExtractionStatusLabel }}
                                    </span>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Motore
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $this->latestTextExtractionEngineLabel }}
                                </dd>
                            </div>

                            @if ($latestTextExtraction)
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Confidenza
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $latestTextExtraction->confidence_score !== null ? $latestTextExtraction->confidence_score . '/100' : '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Completata il
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $latestTextExtraction->completed_at?->format('d/m/Y H:i:s') ?? '—' }}
                                    </dd>
                                </div>

                                @if ($latestTextExtraction->error_message)
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Errore estrazione
                                        </dt>
                                        <dd class="mt-1 rounded-md bg-red-50 p-3 text-sm text-red-700">
                                            {{ $latestTextExtraction->error_message }}
                                        </dd>
                                    </div>
                                @endif

                                @if ($this->latestRawTextPreview)
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Anteprima testo estratto
                                        </dt>
                                        <dd class="mt-2 max-h-80 overflow-y-auto whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-800">
                                            {{ $this->latestRawTextPreview }}
                                        </dd>

                                        @if (mb_strlen($latestTextExtraction->raw_text) > 1500)
                                            <p class="mt-2 text-xs text-gray-500">
                                                Anteprima limitata ai primi 1500 caratteri.
                                            </p>
                                        @endif
                                    </div>
                                @elseif ($latestTextExtraction->status === 'requires_ocr')
                                    <div class="sm:col-span-2 rounded-md border border-orange-200 bg-orange-50 p-4">
                                        <p class="text-sm text-orange-800">
                                            Questo documento richiede OCR. Il file è stato salvato correttamente, ma il testo non è ancora estraibile automaticamente.
                                        </p>
                                    </div>
                                @endif
                            @else
                                <div class="sm:col-span-2">
                                    <p class="text-sm text-gray-600">
                                        Non è ancora stato registrato nessun tentativo di estrazione testo.
                                    </p>
                                </div>
                            @endif
                        </dl>
                    </div>
                </details>
            </div>

            {{-- Sidebar --}}
            <aside class="space-y-6">
                {{-- File originale --}}
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            File originale
                        </h2>

                        @if ($media)
                            <div class="mt-5 space-y-3">
                                <a
                                    href="{{ route('documents.preview', $document) }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex w-full items-center justify-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                                >
                                    Apri anteprima
                                </a>

                                <a
                                    href="{{ route('documents.download', $document) }}"
                                    class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50"
                                >
                                    Scarica file
                                </a>
                            </div>

                            <dl class="mt-6 space-y-4">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        File salvato
                                    </dt>
                                    <dd class="mt-1 break-all text-sm text-gray-900">
                                        {{ $media->file_name }}
                                    </dd>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Disco
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $media->disk }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Dimensione
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $this->formatBytes($media->size) }}
                                        </dd>
                                    </div>
                                </div>
                            </dl>

                            <div class="mt-5 rounded-md border border-yellow-200 bg-yellow-50 p-4">
                                <p class="text-sm text-yellow-800">
                                    Il file è salvato in storage privato e viene servito tramite rotta autorizzata.
                                </p>
                            </div>
                        @else
                            <p class="mt-4 text-sm text-gray-600">
                                Nessun file originale associato a questo documento.
                            </p>
                        @endif
                    </div>
                </section>

                {{-- Processing sintetico --}}
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-lg font-medium text-gray-900">
                                    Processing
                                </h2>

                                <p class="mt-1 text-sm text-gray-600">
                                    Stato tecnico della pipeline.
                                </p>
                            </div>

                            @if ($this->canStartProcessing)
                                <button
                                    type="button"
                                    wire:click="startProcessing"
                                    wire:loading.attr="disabled"
                                    wire:target="startProcessing"
                                    class="inline-flex shrink-0 items-center rounded-md border border-transparent bg-gray-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 disabled:opacity-50"
                                >
                                    Avvia
                                </button>
                            @endif
                        </div>

                        @if (session()->has('processing_success'))
                            <div class="mt-4 rounded-md bg-green-50 p-4 text-sm text-green-700">
                                {{ session('processing_success') }}
                            </div>
                        @endif

                        @if (session()->has('processing_warning'))
                            <div class="mt-4 rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                                {{ session('processing_warning') }}
                            </div>
                        @endif

                        <dl class="mt-5 space-y-4">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Estrazione testo
                                </dt>
                                <dd class="mt-2">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->textExtractionStatusBadgeClasses }}">
                                        {{ $this->textExtractionStatusLabel }}
                                    </span>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Ultimo step
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $this->latestAttemptStepLabel }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Esito ultimo tentativo
                                </dt>
                                <dd class="mt-2">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->latestAttemptStatusBadgeClasses }}">
                                        {{ $this->latestAttemptStatusLabel }}
                                    </span>
                                </dd>
                            </div>
                        </dl>

                        <details class="mt-5 border-t border-gray-200 pt-4">
                            <summary class="cursor-pointer text-sm font-medium text-gray-700">
                                Mostra dettagli processing
                            </summary>

                            @if ($latestProcessingAttempt)
                                <dl class="mt-4 space-y-4">
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Tentativo numero
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $latestProcessingAttempt->attempt_number }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Avviato il
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $latestProcessingAttempt->started_at?->format('d/m/Y H:i:s') ?? '—' }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Completato il
                                        </dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            {{ $latestProcessingAttempt->completed_at?->format('d/m/Y H:i:s') ?? '—' }}
                                        </dd>
                                    </div>

                                    @if ($latestProcessingAttempt->error_message)
                                        <div>
                                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Errore
                                            </dt>
                                            <dd class="mt-1 rounded-md bg-red-50 p-3 text-sm text-red-700">
                                                {{ $latestProcessingAttempt->error_message }}
                                            </dd>
                                        </div>
                                    @endif
                                </dl>
                            @else
                                <p class="mt-4 text-sm text-gray-600">
                                    Non è ancora stato registrato nessun tentativo di processing.
                                </p>
                            @endif
                        </details>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</div>