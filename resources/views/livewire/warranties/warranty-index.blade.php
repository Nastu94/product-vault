{{-- resources/views/livewire/warranties/warranty-index.blade.php --}}

<div class="py-8">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">
                    Garanzie
                </h1>

                <p class="mt-2 text-sm text-gray-600">
                    Panoramica dello stato della copertura, del periodo indicato
                    e della provenienza per i prodotti del workspace.
                </p>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4 xl:grid-cols-8">
            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Totali
                </div>

                <div class="mt-2 text-2xl font-semibold text-gray-900">
                    {{ $summary['total'] }}
                </div>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Nel periodo
                </div>

                <div class="mt-2 text-2xl font-semibold text-green-700">
                    {{ $summary['active'] }}
                </div>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    In scadenza
                </div>

                <div class="mt-2 text-2xl font-semibold text-yellow-700">
                    {{ $summary['expiring'] }}
                </div>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Non iniziate
                </div>

                <div class="mt-2 text-2xl font-semibold text-blue-700">
                    {{ $summary['not_started'] }}
                </div>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Scadute
                </div>

                <div class="mt-2 text-2xl font-semibold text-red-700">
                    {{ $summary['expired'] }}
                </div>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Periodo incerto
                </div>

                <div class="mt-2 text-2xl font-semibold text-gray-700">
                    {{ $summary['unknown'] }}
                </div>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Manuali
                </div>

                <div class="mt-2 text-2xl font-semibold text-gray-900">
                    {{ $summary['manual'] }}
                </div>
            </div>

            <div class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                    Calcolate
                </div>

                <div class="mt-2 text-2xl font-semibold text-gray-900">
                    {{ $summary['calculated'] }}
                </div>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-3 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
            <div>
                <label for="status" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                    Stato del periodo
                </label>

                <select
                    id="status"
                    wire:model.live="status"
                    class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option value="all">
                        Tutti i periodi
                    </option>

                    <option value="active">
                        Nel periodo
                    </option>

                    <option value="expiring">
                        In scadenza
                    </option>

                    <option value="not_started">
                        Non ancora iniziato
                    </option>

                    <option value="expired">
                        Scaduto
                    </option>

                    <option value="unknown">
                        Non determinabile
                    </option>
                </select>
            </div>

            <div>
                <label for="source" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                    Provenienza
                </label>

                <select
                    id="source"
                    wire:model.live="source"
                    class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option value="all">
                        Tutte le provenienze
                    </option>

                    <option value="calculated">
                        Calcolate da Product Vault
                    </option>

                    <option value="manual">
                        Inserite o modificate manualmente
                    </option>
                </select>
            </div>
        </div>

        <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
            <p class="text-sm leading-6 text-blue-900">
                Lo stato del periodo descrive soltanto le date registrate.
                Non certifica, da solo, che la copertura sia applicabile,
                confermata o verificata.
            </p>
        </div>

        <div class="overflow-hidden bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
            @if ($warranties->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Prodotto
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Copertura
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Periodo
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Provenienza
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Documento
                                </th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Azioni
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-200 bg-white">
                            @foreach ($warranties as $warranty)
                                @php
                                    $coverageContext =
                                        $this->warrantyCoverageContext($warranty);

                                    $remainingDays =
                                        $this->warrantyRemainingDays($warranty);

                                    $missingInformationCount =
                                        $this->warrantyMissingInformationCount($warranty);

                                    $coverageIsEstimate =
                                        $this->warrantyCoverageIsEstimate($warranty);

                                    $temporalStatusCode = data_get(
                                        $coverageContext,
                                        'temporal_status.code'
                                    );
                                @endphp

                                <tr>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <div class="font-medium">
                                            {{ $warranty->product?->name ?? 'Prodotto non disponibile' }}
                                        </div>

                                        @if ($warranty->product?->model)
                                            <div class="mt-1 text-xs text-gray-500">
                                                Modello: {{ $warranty->product->model }}
                                            </div>
                                        @endif

                                        @if ($warranty->product?->serial_number)
                                            <div class="mt-1 text-xs text-gray-500">
                                                Seriale: {{ $warranty->product->serial_number }}
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <span
                                            class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->warrantyCoverageStateBadgeClasses($warranty) }}"
                                        >
                                            {{ $this->warrantyCoverageStateLabel($warranty) }}
                                        </span>

                                        <div class="mt-2 text-xs text-gray-600">
                                            {{ data_get(
                                                $coverageContext,
                                                'coverage_type.label',
                                                'Tipo non disponibile'
                                            ) }}
                                        </div>

                                        @if ($coverageIsEstimate)
                                            <div class="mt-1 text-xs font-medium text-yellow-700">
                                                Stima da verificare
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

                                        @if ($warranty->confidence_score !== null)
                                            <div class="mt-1 text-xs text-gray-500">
                                                Confidenza tecnica
                                                {{ $warranty->confidence_score }}/100
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <span
                                            class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->warrantyStatusBadgeClasses($warranty) }}"
                                        >
                                            {{ $this->warrantyStatusLabel($warranty) }}
                                        </span>

                                        <div class="mt-2">
                                            {{ $warranty->starts_at?->format('d/m/Y') ?? '—' }}
                                            →
                                            {{ $warranty->ends_at?->format('d/m/Y') ?? '—' }}
                                        </div>

                                        <div class="mt-1 text-xs text-gray-500">
                                            @if ($warranty->duration_months)
                                                {{ $warranty->duration_months }} mesi indicati
                                            @else
                                                Durata non disponibile
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
                                    </td>

                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        {{ $this->warrantySourceLabel($warranty) }}

                                        @if (data_get(
                                            $coverageContext,
                                            'confirmation.is_confirmed',
                                            false
                                        ))
                                            <div class="mt-1 text-xs text-gray-500">
                                                Dati confermati dall’utente
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        @if ($warranty->sourceDocument)
                                            <a
                                                href="{{ route('documents.show', $warranty->sourceDocument) }}"
                                                class="text-indigo-600 hover:text-indigo-900"
                                            >
                                                {{ $warranty->sourceDocument->original_filename }}
                                            </a>

                                            <div class="mt-1 text-xs text-gray-500">
                                                {{ $warranty->sourceDocument->documentType?->name ?? 'Documento' }}
                                            </div>
                                        @else
                                            —
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-right text-sm">
                                        @if ($warranty->product)
                                            <a
                                                href="{{ route('products.show', $warranty->product) }}"
                                                class="font-medium text-indigo-600 hover:text-indigo-800"
                                            >
                                                Apri prodotto
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-6 py-4">
                    {{ $warranties->links() }}
                </div>
            @else
                <div class="p-8 text-center">
                    <h2 class="text-base font-medium text-gray-900">
                        Nessuna garanzia trovata
                    </h2>

                    <p class="mt-2 text-sm text-gray-600">
                        Le coperture compariranno qui dopo la conferma dei prodotti
                        o la creazione manuale dal dettaglio prodotto.
                    </p>

                    <div class="mt-6">
                        <a
                            href="{{ route('products.index') }}"
                            class="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                        >
                            Vai ai prodotti
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>