{{-- resources/views/livewire/documents.document-show --}}

<div class="py-8">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6 flex items-start justify-between gap-4">
            <div>
                <a
                    href="{{ route('documents.index') }}"
                    class="text-sm text-gray-600 hover:text-gray-900"
                >
                    ← Torna ai documenti
                </a>

                <h1 class="mt-4 text-2xl font-semibold text-gray-900">
                    {{ $document->original_filename ?? 'Documento #' . $document->id }}
                </h1>

                <div class="mt-3">
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->statusBadgeClasses }}">
                        {{ $this->statusLabel }}
                    </span>
                </div>
            </div>

            <a
                href="{{ route('documents.upload') }}"
                class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700"
            >
                Carica altro
            </a>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 bg-white shadow-xl sm:rounded-lg">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900">
                        Dettagli documento
                    </h2>

                    <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                                Tipo documento
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ $document->documentType?->name ?? 'Non classificato' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                Venditore
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ $document->merchant?->name ?? '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                Data acquisto
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ $document->purchase_date?->format('d/m/Y') ?? '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                Totale
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                @if ($document->total_amount)
                                    {{ number_format($document->total_amount, 2, ',', '.') }}
                                    {{ $document->currency?->code }}
                                @else
                                    —
                                @endif
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

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                Sorgente
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ $document->source === 'manual_upload' ? 'Upload manuale' : ($document->source ?? '—') }}
                            </dd>
                        </div>
                    </dl>

                    {{-- Se il documento è in stato "caricato" o "in elaborazione",
                             mostriamo il pannello di controllo del processing --}}
                    <div class="mt-8 border-t border-gray-200 pt-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-base font-medium text-gray-900">
                                    Processing documento
                                </h3>

                                <p class="mt-1 text-sm text-gray-600">
                                    Stato tecnico della preparazione del documento per estrazione, classificazione e revisione.
                                </p>
                            </div>

                            @if ($this->canStartProcessing)
                                <button
                                    type="button"
                                    wire:click="startProcessing"
                                    wire:loading.attr="disabled"
                                    wire:target="startProcessing"
                                    class="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 disabled:opacity-50"
                                >
                                    Avvia processing
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

                        <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                                    Ultimo tentativo
                                </dt>

                                <dd class="mt-2">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->latestAttemptStatusBadgeClasses }}">
                                        {{ $this->latestAttemptStatusLabel }}
                                    </span>
                                </dd>
                            </div>

                            @if ($latestProcessingAttempt)
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Step
                                    </dt>

                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $this->latestAttemptStepLabel }}
                                    </dd>
                                </div>

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
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Errore
                                        </dt>

                                        <dd class="mt-1 rounded-md bg-red-50 p-3 text-sm text-red-700">
                                            {{ $latestProcessingAttempt->error_message }}
                                        </dd>
                                    </div>
                                @endif
                            @else
                                <div class="sm:col-span-2">
                                    <p class="text-sm text-gray-600">
                                        Non è ancora stato registrato nessun tentativo di processing per questo documento.
                                    </p>
                                </div>
                            @endif
                        </dl>
                    </div>

                    {{-- Solo se è stato registrato almeno un tentativo di estrazione testo, 
                            mostriamo i dettagli dell'ultimo tentativo --}}
                    <div class="mt-8 border-t border-gray-200 pt-6">
                        <h3 class="text-base font-medium text-gray-900">
                            Ultima estrazione testo
                        </h3>

                        <p class="mt-1 text-sm text-gray-600">
                            Risultato dell’ultimo tentativo di lettura del contenuto testuale del documento.
                        </p>

                        <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                                    <div class="sm:col-span-2">
                                        <div class="rounded-md border border-orange-200 bg-orange-50 p-4">
                                            <p class="text-sm text-orange-800">
                                                Questo documento richiede OCR. Il file è stato salvato correttamente, ma il testo non è ancora estraibile automaticamente.
                                            </p>
                                        </div>
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

                    {{-- Se è stato registrato almeno un tentativo di classificazione, 
                            mostriamo i dettagli dell'ultimo tentativo --}}
                    <div class="mt-8 border-t border-gray-200 pt-6">
                        <h3 class="text-base font-medium text-gray-900">
                            Classificazione documento
                        </h3>

                        <p class="mt-1 text-sm text-gray-600">
                            Tipo documento stimato automaticamente in base al testo estratto.
                        </p>

                        @if ($selectedClassification)
                            <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                                        Motore
                                    </dt>

                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $this->selectedClassificationEngineLabel }}
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

                                @php
                                    $matchedSignals = $selectedClassification->metadata['matched_signals'] ?? [];
                                @endphp

                                @if (! empty($matchedSignals))
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Segnali trovati
                                        </dt>

                                        <dd class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($matchedSignals as $signal)
                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">
                                                    {{ $signal }}
                                                </span>
                                            @endforeach
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        @elseif ($document->text_extraction_status === 'requires_ocr')
                            <div class="mt-4 rounded-md border border-orange-200 bg-orange-50 p-4">
                                <p class="text-sm text-orange-800">
                                    La classificazione automatica partirà dopo l’OCR, perché il documento non ha ancora testo estraibile.
                                </p>
                            </div>
                        @else
                            <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-4">
                                <p class="text-sm text-gray-700">
                                    Nessuna classificazione disponibile per questo documento.
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-xl sm:rounded-lg">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-900">
                        File originale
                    </h2>

                    @php
                        $media = $document->getFirstMedia('original_file');
                    @endphp

                    @if ($media)
                        <div class="mt-6 space-y-4">
                            <div class="mt-6 space-y-3">
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
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    File salvato
                                </dt>
                                <dd class="mt-1 break-all text-sm text-gray-900">
                                    {{ $media->file_name }}
                                </dd>
                            </div>

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
                                    Dimensione media
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $this->formatBytes($media->size) }}
                                </dd>
                            </div>

                            <div class="rounded-md border border-yellow-200 bg-yellow-50 p-4">
                                <p class="text-sm text-yellow-800">
                                    Il file è salvato in storage privato. L’anteprima viene servita tramite rotta autorizzata.
                                </p>
                            </div>
                        </div>
                    @else
                        <p class="mt-4 text-sm text-gray-600">
                            Nessun file originale associato a questo documento.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>