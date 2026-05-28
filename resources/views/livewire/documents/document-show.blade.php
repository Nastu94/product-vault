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

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Colonna principale --}}
            <div class="space-y-6 lg:col-span-2">
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

                            @if ($document->status === 'parsed')
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

                        <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Data rilevata
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $document->purchase_date?->format('d/m/Y') ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Totale rilevato
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
                                    Valuta
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $document->currency?->code ?? '—' }}
                                </dd>
                            </div>
                        </dl>

                        @if ($document->status === 'parsed')
                            <div class="mt-5 rounded-md border border-blue-200 bg-blue-50 p-4">
                                <p class="text-sm text-blue-700">
                                    Questi dati sono candidati automatici. Nel flusso di revisione l’utente potrà confermarli o correggerli.
                                </p>
                            </div>
                        @endif
                    </div>
                </section>

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