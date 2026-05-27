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