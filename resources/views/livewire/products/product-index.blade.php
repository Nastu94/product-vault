{{-- resources/views/livewire/products/product-index.blade.php --}}

<div class="py-8">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">
                    Prodotti
                </h1>

                <p class="mt-2 text-sm text-gray-600">
                    Prodotti creati o confermati a partire dai documenti caricati.
                </p>
            </div>
        </div>

        <div class="overflow-hidden bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
            @if ($products->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Prodotto
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Venditore
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Acquisto
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Copertura e periodo
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Affidabilità prodotto
                                </th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Azioni
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($products as $product)
                                <tr>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <div class="font-medium">
                                            {{ $product->name }}
                                        </div>

                                        @if ($product->model)
                                            <div class="mt-1 text-xs text-gray-500">
                                                Modello: {{ $product->model }}
                                            </div>
                                        @endif

                                        @if ($product->ean_code)
                                            <div class="mt-1 text-xs text-gray-500">
                                                EAN: {{ $product->ean_code }}
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ $product->merchant?->name ?? '—' }}
                                    </td>

                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <div>
                                            {{ $product->purchase_date?->format('d/m/Y') ?? '—' }}
                                        </div>

                                        <div class="mt-1 text-xs text-gray-500">
                                            @if ($product->purchase_price)
                                                {{ number_format($product->purchase_price, 2, ',', '.') }}
                                                {{ $product->currency?->code }}
                                            @else
                                                Prezzo non disponibile
                                            @endif
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        @php
                                            $warranty =
                                                $this->primaryWarranty($product);

                                            $coverageContext =
                                                $this->warrantyCoverageContext($warranty);

                                            $remainingDays =
                                                $this->warrantyRemainingDays($warranty);

                                            $coverageIsEstimate =
                                                $this->warrantyCoverageIsEstimate($warranty);

                                            $missingInformationCount =
                                                $this->warrantyMissingInformationCount($warranty);

                                            $temporalStatusCode = data_get(
                                                $coverageContext,
                                                'temporal_status.code'
                                            );

                                            $coverageIsConfirmed = (bool) data_get(
                                                $coverageContext,
                                                'confirmation.is_confirmed',
                                                false
                                            );
                                        @endphp

                                        @if ($warranty)
                                            <div class="flex flex-col items-start gap-2">
                                                <span
                                                    class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->warrantyCoverageStateBadgeClasses($warranty) }}"
                                                >
                                                    {{ $this->warrantyCoverageStateLabel($warranty) }}
                                                </span>

                                                <span
                                                    class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->warrantyStatusBadgeClasses($warranty) }}"
                                                >
                                                    Periodo:
                                                    {{ $this->warrantyStatusLabel($warranty) }}
                                                </span>
                                            </div>

                                            <div class="mt-2 text-xs text-gray-600">
                                                {{ $this->warrantyCoverageTypeLabel($warranty) }}
                                            </div>

                                            @if ($coverageIsEstimate)
                                                <div class="mt-1 text-xs font-medium text-yellow-700">
                                                    Stima da verificare
                                                </div>
                                            @endif

                                            @if ($coverageIsConfirmed)
                                                <div class="mt-1 text-xs text-gray-500">
                                                    Dati confermati dall’utente
                                                </div>
                                            @endif

                                            @if ($missingInformationCount > 0)
                                                <div class="mt-1 text-xs text-gray-500">
                                                    {{ $missingInformationCount }}
                                                    {{ $missingInformationCount === 1
                                                        ? 'informazione da completare'
                                                        : 'informazioni da completare' }}
                                                </div>
                                            @endif

                                            <div class="mt-2 text-xs text-gray-500">
                                                @if ($warranty->starts_at || $warranty->ends_at)
                                                    Periodo indicato:

                                                    {{ $warranty->starts_at?->format('d/m/Y') ?? '—' }}

                                                    →

                                                    {{ $warranty->ends_at?->format('d/m/Y') ?? '—' }}
                                                @else
                                                    Periodo non disponibile
                                                @endif
                                            </div>

                                            @if ($remainingDays !== null)
                                                <div class="mt-1 text-xs text-gray-500">
                                                    @if ($remainingDays < 0)
                                                        Periodo terminato da
                                                        {{ abs($remainingDays) }}
                                                        giorni
                                                    @elseif ($temporalStatusCode === 'not_started')
                                                        Il periodo termina tra
                                                        {{ $remainingDays }}
                                                        giorni
                                                    @else
                                                        Il periodo termina tra
                                                        {{ $remainingDays }}
                                                        giorni
                                                    @endif
                                                </div>
                                            @endif

                                            <div class="mt-1 text-xs text-gray-500">
                                                Provenienza:
                                                {{ $this->warrantySourceLabel($warranty) }}
                                            </div>
                                        @else
                                            <span
                                                class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-500/20"
                                            >
                                                Nessuna copertura
                                            </span>

                                            <div class="mt-2 text-xs text-gray-500">
                                                Nessun periodo registrato
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        @if ($product->reliability_score !== null)
                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset
                                                @if ($product->reliability_score >= 80)
                                                    bg-green-50 text-green-700 ring-green-600/20
                                                @elseif ($product->reliability_score >= 50)
                                                    bg-yellow-50 text-yellow-800 ring-yellow-600/20
                                                @else
                                                    bg-red-50 text-red-700 ring-red-600/20
                                                @endif
                                            ">
                                                {{ $product->reliability_score }}/100
                                            </span>
                                        @else
                                            —
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-right text-sm">
                                        <a
                                            href="{{ route('products.show', $product) }}"
                                            class="font-medium text-indigo-600 hover:text-indigo-800"
                                        >
                                            Apri
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-6 py-4">
                    {{ $products->links() }}
                </div>
            @else
                <div class="p-8 text-center">
                    <h2 class="text-base font-medium text-gray-900">
                        Nessun prodotto presente
                    </h2>

                    <p class="mt-2 text-sm text-gray-600">
                        I prodotti compariranno qui dopo la conferma di un candidato prodotto dalla scheda documento.
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
        </div>
    </div>
</div>