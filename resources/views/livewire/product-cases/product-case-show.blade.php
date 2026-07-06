{{-- resources/views/livewire/product-cases/product-case-show.blade.php --}}

<div class="py-8">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6">
            <a
                href="{{ route('products.show', $productCase->product) }}"
                class="text-sm text-gray-600 hover:text-gray-900"
            >
                ← Torna al prodotto
            </a>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <h1
                    data-testid="product-case-title"
                    class="text-2xl font-semibold text-gray-900"
                >
                    {{ $productCase->title }}
                </h1>

                <span
                    class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $statusBadgeClasses }}"
                >
                    {{ $statusLabel }}
                </span>

                <span
                    data-testid="product-case-readiness"
                    class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $readinessBadgeClasses }}"
                >
                    {{ $readinessLabel }}
                </span>
            </div>

            <p class="mt-2 text-sm text-gray-600">
                Dettaglio della pratica relativa a
                <span class="font-medium text-gray-900">
                    {{ $productCase->product?->name ?? 'prodotto non disponibile' }}
                </span>.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                {{-- Problema --}}
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-medium text-gray-900">
                                    Problema segnalato
                                </h2>

                                <p class="mt-1 text-sm text-gray-600">
                                    Informazioni correnti registrate nella pratica.
                                </p>
                            </div>

                            @if (
                                $productCase->status
                                    === \App\Models\ProductCase::STATUS_DRAFT
                            )
                                @can('update', $productCase)
                                    @if (! $isEditingDetails)
                                        <button
                                            type="button"
                                            data-testid="start-product-case-details-edit"
                                            wire:click="startDetailsEdit"
                                            class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                        >
                                            Modifica dati
                                        </button>
                                    @endif
                                @endcan
                            @endif
                        </div>

                        @if ($detailsSuccessMessage)
                            <div
                                data-testid="product-case-details-success"
                                class="mt-5 rounded-md bg-green-50 p-4 text-sm text-green-800 ring-1 ring-inset ring-green-600/20"
                            >
                                {{ $detailsSuccessMessage }}
                            </div>
                        @endif

                        @if (
                            $productCase->status
                                === \App\Models\ProductCase::STATUS_DRAFT
                            && $isEditingDetails
                        )
                            @include(
                                'livewire.product-cases.partials.details-editor'
                            )
                        @else
                            <dl class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Descrizione
                                    </dt>

                                    <dd class="mt-1 whitespace-pre-wrap text-sm leading-6 text-gray-900">{{ $productCase->description ?: '—' }}</dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Data del problema
                                    </dt>

                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $productCase->occurred_on?->format('d/m/Y') ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Utilizzabilità
                                    </dt>

                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $usabilityLabel }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Danno accidentale dichiarato
                                    </dt>

                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $accidentalDamageLabel }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Aperta da
                                    </dt>

                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $productCase->openedBy?->name ?? '—' }}
                                    </dd>
                                </div>

                                @if ($productCase->accidental_damage_notes)
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Note sul possibile danno accidentale
                                        </dt>

                                        <dd class="mt-1 whitespace-pre-wrap text-sm leading-6 text-gray-900">{{ $productCase->accidental_damage_notes }}</dd>
                                    </div>
                                @endif
                            </dl>
                        @endif
                    </div>
                </section>

                {{-- Evidenze --}}
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <div>
                            <h2 class="text-lg font-medium text-gray-900">
                                Evidenze selezionate
                            </h2>

                            <p class="mt-1 text-sm text-gray-600">
                                Documenti e fotografie attualmente collegati alla pratica.
                            </p>
                        </div>

                        <div
                            data-testid="product-case-documents"
                            class="mt-6"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        Documenti
                                    </h3>

                                    <p class="mt-1 text-xs text-gray-500">
                                        Documenti scelti come evidenze della pratica.
                                    </p>
                                </div>

                                @can('update', $productCase)
                                    @if (
                                        ! $isManagingDocuments
                                        && ! $isEditingDetails
                                    )
                                        <button
                                            type="button"
                                            data-testid="start-product-case-document-management"
                                            wire:click="startDocumentManagement"
                                            class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                        >
                                            Gestisci documenti
                                        </button>
                                    @endif
                                @endcan
                            </div>

                            @if ($documentsSuccessMessage)
                                <div
                                    data-testid="product-case-documents-success"
                                    class="mt-4 rounded-md bg-green-50 p-4 text-sm text-green-800 ring-1 ring-inset ring-green-600/20"
                                >
                                    {{ $documentsSuccessMessage }}
                                </div>
                            @endif

                            @if ($productCase->documents->isNotEmpty())
                                <div class="mt-3 space-y-3">
                                    @foreach ($productCase->documents as $document)
                                        <div class="rounded-md border border-gray-200 p-4">
                                            <div class="flex items-start justify-between gap-4">
                                                <a
                                                    href="{{ route('documents.show', $document) }}"
                                                    class="min-w-0 flex-1 hover:opacity-80"
                                                >
                                                    <div class="break-words text-sm font-medium text-gray-900">
                                                        {{ $document->original_filename }}
                                                    </div>

                                                    <div class="mt-1 flex flex-wrap gap-2 text-xs text-gray-500">
                                                        <span>
                                                            {{ $document->documentType?->name ?? 'Documento' }}
                                                        </span>

                                                        @if ($document->merchant)
                                                            <span>
                                                                {{ $document->merchant->name }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                </a>

                                                @if ($isManagingDocuments)
                                                    <button
                                                        type="button"
                                                        data-testid="deselect-product-case-document-{{ $document->id }}"
                                                        wire:click="deselectDocument({{ $document->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="deselectDocument({{ $document->id }})"
                                                        class="shrink-0 rounded-md border border-red-200 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        Rimuovi
                                                    </button>
                                                @endif
                                            </div>

                                            @if ($document->pivot?->notes)
                                                <p class="mt-3 text-xs leading-5 text-gray-600">
                                                    {{ $document->pivot->notes }}
                                                </p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="mt-3 rounded-md bg-gray-50 p-4 text-sm text-gray-600">
                                    Nessun documento selezionato.
                                </div>
                            @endif

                            @if ($isManagingDocuments)
                                @include(
                                    'livewire.product-cases.partials.document-manager'
                                )
                            @endif
                        </div>

                        <div
                            data-testid="product-case-photos"
                            class="mt-8"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        Fotografie private
                                    </h3>

                                    <p class="mt-1 text-xs text-gray-500">
                                        Immagini conservate privatamente come evidenze
                                        della pratica.
                                    </p>
                                </div>

                                @if (
                                    ! in_array(
                                        $productCase->status,
                                        [
                                            \App\Models\ProductCase::STATUS_CLOSED,
                                            \App\Models\ProductCase::STATUS_CANCELLED,
                                        ],
                                        true
                                    )
                                )
                                    @can('update', $productCase)
                                        @if (
                                            ! $isManagingPhotos
                                            && ! $isManagingDocuments
                                            && ! $isEditingDetails
                                        )
                                            <button
                                                type="button"
                                                data-testid="start-product-case-photo-management"
                                                wire:click="startPhotoManagement"
                                                class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                            >
                                                Gestisci fotografie
                                            </button>
                                        @endif
                                    @endcan
                                @endif
                            </div>

                            @if ($photosSuccessMessage)
                                <div
                                    data-testid="product-case-photos-success"
                                    class="mt-4 rounded-md bg-green-50 p-4 text-sm text-green-800 ring-1 ring-inset ring-green-600/20"
                                >
                                    {{ $photosSuccessMessage }}
                                </div>
                            @endif

                            @if ($issuePhotos !== [])
                                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    @foreach ($issuePhotos as $photo)
                                        <div class="rounded-md border border-gray-200 p-4">
                                            <div class="flex items-start justify-between gap-4">
                                                <div class="min-w-0 flex-1">
                                                    <div class="break-words text-sm font-medium text-gray-900">
                                                        {{ $photo['original_filename'] }}
                                                    </div>

                                                    <div class="mt-2 space-y-1 text-xs text-gray-500">
                                                        <div>
                                                            Tipo:
                                                            {{ $photo['mime_type'] ?? '—' }}
                                                        </div>

                                                        <div>
                                                            Dimensione:
                                                            {{ number_format(
                                                                ((int) $photo['size']) / 1024,
                                                                1,
                                                                ',',
                                                                '.'
                                                            ) }}
                                                            KB
                                                        </div>

                                                        @if ($photo['uploaded_at'])
                                                            <div>
                                                                Caricata:
                                                                {{ \Illuminate\Support\Carbon::parse(
                                                                    $photo['uploaded_at']
                                                                )->format('d/m/Y H:i') }}
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>

                                                @if ($isManagingPhotos)
                                                    <button
                                                        type="button"
                                                        data-testid="remove-product-case-photo-{{ $photo['id'] }}"
                                                        wire:click="removePhoto({{ $photo['id'] }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="removePhoto({{ $photo['id'] }})"
                                                        class="shrink-0 rounded-md border border-red-200 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        Rimuovi
                                                    </button>
                                                @endif
                                            </div>

                                            <p class="mt-3 text-xs leading-5 text-gray-500">
                                                File privato. Anteprima e download non sono
                                                ancora disponibili in questa pagina.
                                            </p>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="mt-3 rounded-md bg-gray-50 p-4 text-sm text-gray-600">
                                    Nessuna fotografia collegata.
                                </div>
                            @endif

                            @if ($isManagingPhotos)
                                @include(
                                    'livewire.product-cases.partials.photo-manager'
                                )
                            @endif
                        </div>
                    </div>
                </section>

                {{-- Bozza --}}
                <section
                    data-testid="product-case-request-draft"
                    class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg"
                >
                    <div class="p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-medium text-gray-900">
                                    Bozza di richiesta
                                </h2>

                                <p class="mt-1 text-sm text-gray-600">
                                    Testo corrente preparato per il contatto.
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-3">
                                @if (
                                    in_array(
                                        $productCase->status,
                                        [
                                            \App\Models\ProductCase::STATUS_DRAFT,
                                            \App\Models\ProductCase::STATUS_READY_TO_CONTACT,
                                        ],
                                        true
                                    )
                                )
                                    @can('update', $productCase)
                                        @if (
                                            ! $isEditingDetails
                                            && ! $isManagingDocuments
                                            && ! $isManagingPhotos
                                        )
                                            <button
                                                type="button"
                                                data-testid="generate-product-case-request-draft"
                                                wire:click="generateRequestDraft"
                                                wire:loading.attr="disabled"
                                                wire:target="generateRequestDraft"
                                                class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                @if (
                                                    is_string($productCase->request_draft)
                                                    && trim($productCase->request_draft) !== ''
                                                )
                                                    Rigenera bozza
                                                @else
                                                    Genera bozza
                                                @endif
                                            </button>
                                        @endif
                                    @endcan
                                @endif

                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-500/20">
                                    {{ $requestDraftSourceLabel }}
                                </span>
                            </div>
                        </div>

                        @if ($requestDraftSuccessMessage)
                            <div
                                data-testid="product-case-request-draft-success"
                                class="mt-5 rounded-md bg-green-50 p-4 text-sm text-green-800 ring-1 ring-inset ring-green-600/20"
                            >
                                {{ $requestDraftSuccessMessage }}
                            </div>
                        @endif

                        @if ($requestDraftErrorMessage)
                            <div
                                data-testid="product-case-request-draft-error"
                                class="mt-5 rounded-md bg-red-50 p-4 text-sm text-red-800 ring-1 ring-inset ring-red-600/20"
                            >
                                {{ $requestDraftErrorMessage }}
                            </div>
                        @endif

                        @if (
                            is_string($productCase->request_draft)
                            && trim($productCase->request_draft) !== ''
                        )
                            <div class="mt-6 rounded-md bg-gray-50 p-4 ring-1 ring-inset ring-gray-200">
                                <pre class="whitespace-pre-wrap break-words font-sans text-sm leading-6 text-gray-900">{{ $productCase->request_draft }}</pre>
                            </div>

                            @if ($productCase->request_draft_generated_at)
                                <p class="mt-3 text-xs text-gray-500">
                                    Ultima generazione automatica:
                                    {{ $productCase->request_draft_generated_at->format('d/m/Y H:i') }}
                                </p>
                            @endif
                        @else
                            <div class="mt-6 rounded-md bg-gray-50 p-4 text-sm text-gray-600">
                                Nessuna bozza disponibile.
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Timeline --}}
                <section
                    data-testid="product-case-timeline"
                    class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg"
                >
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Timeline della pratica
                        </h2>

                        <p class="mt-1 text-sm text-gray-600">
                            Storico operativo append-only delle attività registrate.
                        </p>

                        @if (($timeline['events'] ?? []) !== [])
                            <div class="mt-6 flow-root">
                                <ul role="list" class="-mb-8">
                                    @foreach ($timeline['events'] as $event)
                                        @php
                                            $categoryClasses = match ($event['category'] ?? null) {
                                                'workflow' =>
                                                    'bg-blue-50 text-blue-700 ring-blue-600/20',

                                                'evidence' =>
                                                    'bg-purple-50 text-purple-700 ring-purple-600/20',

                                                'request_draft' =>
                                                    'bg-indigo-50 text-indigo-700 ring-indigo-600/20',

                                                default =>
                                                    'bg-gray-100 text-gray-700 ring-gray-500/20',
                                            };
                                        @endphp

                                        <li>
                                            <div class="relative pb-8">
                                                @if (! $loop->last)
                                                    <span
                                                        class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-gray-200"
                                                        aria-hidden="true"
                                                    ></span>
                                                @endif

                                                <div class="relative flex space-x-3">
                                                    <div>
                                                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 ring-8 ring-white">
                                                            <span class="text-xs font-semibold text-gray-700">
                                                                {{ strtoupper(
                                                                    substr(
                                                                        $event['label'] ?? 'E',
                                                                        0,
                                                                        1
                                                                    )
                                                                ) }}
                                                            </span>
                                                        </span>
                                                    </div>

                                                    <div class="flex min-w-0 flex-1 justify-between gap-4 pt-1.5">
                                                        <div class="min-w-0">
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <p class="text-sm font-medium text-gray-900">
                                                                    {{ $event['summary'] ?? $event['label'] ?? 'Evento pratica' }}
                                                                </p>

                                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $categoryClasses }}">
                                                                    {{ $event['category_label'] ?? 'Altro' }}
                                                                </span>
                                                            </div>

                                                            @if ($event['description'] ?? null)
                                                                <p class="mt-1 text-sm leading-6 text-gray-600">
                                                                    {{ $event['description'] }}
                                                                </p>
                                                            @endif

                                                            @if (data_get($event, 'reference.label'))
                                                                <p class="mt-2 text-xs text-gray-500">
                                                                    Riferimento:
                                                                    {{ data_get($event, 'reference.label') }}

                                                                    @if (data_get($event, 'reference.state'))
                                                                        · {{ data_get($event, 'reference.state') }}
                                                                    @endif
                                                                </p>
                                                            @endif
                                                        </div>

                                                        <div class="shrink-0 whitespace-nowrap text-right text-xs text-gray-500">
                                                            <div>
                                                                @if ($event['occurred_at'] ?? null)
                                                                    {{ \Illuminate\Support\Carbon::parse(
                                                                        $event['occurred_at']
                                                                    )->format('d/m/Y H:i') }}
                                                                @else
                                                                    —
                                                                @endif
                                                            </div>

                                                            <div class="mt-1">
                                                                {{ data_get(
                                                                    $event,
                                                                    'actor.name',
                                                                    'Sistema'
                                                                ) }}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <div class="mt-6 rounded-md bg-gray-50 p-4 text-sm text-gray-600">
                                Nessun evento registrato.
                            </div>
                        @endif
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                {{-- Prodotto --}}
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Prodotto
                        </h2>

                        <a
                            href="{{ route('products.show', $productCase->product) }}"
                            class="mt-5 block rounded-md border border-gray-200 p-4 hover:bg-gray-50"
                        >
                            <div class="text-sm font-medium text-gray-900">
                                {{ $productCase->product?->name ?? 'Prodotto non disponibile' }}
                            </div>

                            <div class="mt-2 space-y-1 text-xs text-gray-500">
                                @if ($productCase->product?->brand)
                                    <div>
                                        Brand:
                                        {{ $productCase->product->brand->name }}
                                    </div>
                                @endif

                                @if ($productCase->product?->model)
                                    <div>
                                        Modello:
                                        {{ $productCase->product->model }}
                                    </div>
                                @endif

                                @if ($productCase->product?->merchant)
                                    <div>
                                        Venditore:
                                        {{ $productCase->product->merchant->name }}
                                    </div>
                                @endif

                                @if ($productCase->product?->purchase_date)
                                    <div>
                                        Acquistato:
                                        {{ $productCase->product->purchase_date->format('d/m/Y') }}
                                    </div>
                                @endif
                            </div>
                        </a>
                    </div>
                </section>

                {{-- Readiness --}}
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Completezza operativa
                        </h2>

                        <div class="mt-4">
                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $readinessBadgeClasses }}">
                                {{ $readinessLabel }}
                            </span>
                        </div>

                        <p class="mt-4 text-xs leading-5 text-gray-500">
                            La completezza indica se i dati sono sufficienti per preparare il contatto.
                            Non rappresenta una decisione automatica sulla copertura legale.
                        </p>

                        @if (($readiness['blocking_information'] ?? []) !== [])
                            <div class="mt-5">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-red-700">
                                    Informazioni bloccanti
                                </h3>

                                <ul class="mt-2 space-y-2 text-sm text-red-800">
                                    @foreach ($readiness['blocking_information'] as $item)
                                        <li class="flex items-start gap-2">
                                            <span aria-hidden="true">•</span>
                                            <span>
                                                {{ $item['label'] ?? 'Informazione mancante' }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if (($readiness['advisory_information'] ?? []) !== [])
                            <div class="mt-5">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-yellow-700">
                                    Avvisi
                                </h3>

                                <ul class="mt-2 space-y-2 text-sm text-yellow-900">
                                    @foreach ($readiness['advisory_information'] as $item)
                                        <li class="flex items-start gap-2">
                                            <span aria-hidden="true">•</span>
                                            <span>
                                                {{ $item['label'] ?? 'Informazione da verificare' }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Date --}}
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Date della pratica
                        </h2>

                        <dl class="mt-5 space-y-4">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Apertura
                                </dt>

                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $productCase->opened_at?->format('d/m/Y H:i') ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Contatto
                                </dt>

                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $productCase->contacted_at?->format('d/m/Y H:i') ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Risoluzione
                                </dt>

                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $productCase->resolved_at?->format('d/m/Y H:i') ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Chiusura
                                </dt>

                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $productCase->closed_at?->format('d/m/Y H:i') ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Annullamento
                                </dt>

                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $productCase->cancelled_at?->format('d/m/Y H:i') ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </section>

                @if ($productCase->outcome || $productCase->resolution_notes)
                    <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                        <div class="p-6">
                            <h2 class="text-lg font-medium text-gray-900">
                                Esito
                            </h2>

                            <dl class="mt-5 space-y-4">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Codice esito
                                    </dt>

                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $productCase->outcome ?? '—' }}
                                    </dd>
                                </div>

                                @if ($productCase->resolution_notes)
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Note
                                        </dt>

                                        <dd class="mt-1 whitespace-pre-wrap text-sm leading-6 text-gray-900">{{ $productCase->resolution_notes }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </div>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</div>