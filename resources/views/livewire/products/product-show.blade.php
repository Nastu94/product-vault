{{-- resources/views/livewire/products/product-show.blade.php --}}

<div class="py-8">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6">
            <a
                href="{{ route('products.index') }}"
                class="text-sm text-gray-600 hover:text-gray-900"
            >
                ← Torna ai prodotti
            </a>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold text-gray-900">
                    {{ $product->name }}
                </h1>

                @if ($product->reliability_score !== null)
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->reliabilityBadgeClasses }}">
                        Affidabilità {{ $product->reliability_score }}/100
                    </span>
                @endif
            </div>

            <p class="mt-2 text-sm text-gray-600">
                Scheda prodotto creata da documento e confermata dall’utente.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Dati prodotto
                        </h2>

                        <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Nome
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->name }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Modello
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->model ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    EAN
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->ean_code ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Numero seriale
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->serial_number ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Stato identificazione
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $this->identificationStatusLabel }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Creato da
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->createdBy?->name ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Acquisto
                        </h2>

                        <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Data acquisto
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->purchase_date?->format('d/m/Y') ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Prezzo unitario
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    @if ($product->purchase_price)
                                        {{ number_format($product->purchase_price, 2, ',', '.') }}
                                        {{ $product->currency?->code }}
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Venditore
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->merchant?->name ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Valuta
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->currency?->code ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-medium text-gray-900">
                                    Garanzia
                                </h2>

                                <p class="mt-1 text-sm text-gray-600">
                                    Garanzia stimata in base alla data di acquisto e alle regole configurate.
                                </p>
                            </div>

                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->warrantyStatusBadgeClasses }}">
                                {{ $this->warrantyStatusLabel }}
                            </span>
                        </div>

                        @if ($this->primaryWarranty)
                            <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tipo
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $this->primaryWarranty->warrantyType?->name ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Durata
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if ($this->primaryWarranty->duration_months)
                                            {{ $this->primaryWarranty->duration_months }} mesi
                                        @else
                                            —
                                        @endif
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Inizio
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $this->primaryWarranty->starts_at?->format('d/m/Y') ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Scadenza
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $this->primaryWarranty->ends_at?->format('d/m/Y') ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Giorni residui
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if ($this->warrantyRemainingDays === null)
                                            —
                                        @elseif ($this->warrantyRemainingDays < 0)
                                            Scaduta da {{ abs($this->warrantyRemainingDays) }} giorni
                                        @else
                                            {{ $this->warrantyRemainingDays }} giorni
                                        @endif
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Fonte
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $this->primaryWarranty->source === 'calculated' ? 'Calcolata automaticamente' : $this->primaryWarranty->source }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Affidabilità
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if ($this->primaryWarranty->confidence_score !== null)
                                            {{ $this->primaryWarranty->confidence_score }}/100
                                        @else
                                            —
                                        @endif
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Documento sorgente
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if ($this->primaryWarranty->sourceDocument)
                                            <a
                                                href="{{ route('documents.show', $this->primaryWarranty->sourceDocument) }}"
                                                class="text-indigo-600 hover:text-indigo-900"
                                            >
                                                {{ $this->primaryWarranty->sourceDocument->original_filename }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </dd>
                                </div>
                            </dl>

                            @if ($this->primaryWarranty->metadata['source_note'] ?? null)
                                <div class="mt-6 rounded-md bg-gray-50 p-4">
                                    <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Nota regola
                                    </div>

                                    <p class="mt-1 text-sm text-gray-700">
                                        {{ $this->primaryWarranty->metadata['source_note'] }}
                                    </p>
                                </div>
                            @endif
                        @else
                            <div class="mt-6 rounded-md bg-gray-50 p-4">
                                <p class="text-sm text-gray-700">
                                    Nessuna garanzia calcolata per questo prodotto.
                                </p>

                                <p class="mt-1 text-xs text-gray-500">
                                    Di solito serve almeno una data di acquisto valida.
                                </p>
                            </div>
                        @endif
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Documenti collegati
                        </h2>

                        @if ($product->documents->isNotEmpty())
                            <div class="mt-5 space-y-3">
                                @foreach ($product->documents as $document)
                                    <a
                                        href="{{ route('documents.show', $document) }}"
                                        class="block rounded-md border border-gray-200 p-4 hover:bg-gray-50"
                                    >
                                        <div class="text-sm font-medium text-gray-900">
                                            {{ $document->original_filename }}
                                        </div>

                                        <div class="mt-1 text-xs text-gray-500">
                                            {{ $document->documentType?->name ?? 'Documento' }}
                                            · {{ $document->created_at?->format('d/m/Y H:i') }}
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-4 text-sm text-gray-600">
                                Nessun documento collegato.
                            </p>
                        @endif
                    </div>
                </section>
            </aside>
        </div>
    </div>
</div>