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